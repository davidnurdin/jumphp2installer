<?php

namespace App\Controller;

use App\Entity\Package;
use App\Entity\User;
use App\Repository\PackageRepository;
use App\Service\Installers;
use App\Service\PackageFiles;
use Doctrine\ORM\EntityManagerInterface;
use JumPHP2Installer\Package as InstallerPackage;
use JumPHP2Installer\Runner;
use JumPHP2Installer\Script;
use JumPHP2Installer\Session;
use JumPHP2Installer\Vars;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The author's side: their packages, the editor (script, files, preview, downloads). */
#[IsGranted('ROLE_USER')]
#[Route('/packages')]
final class PackageController extends AbstractController
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly EntityManagerInterface $em,
        private readonly PackageFiles $files,
    ) {
    }

    #[Route('', name: 'packages', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('package/index.html.twig', ['packages' => $this->packages->ofOwner($this->user())]);
    }

    #[Route('/new', name: 'package_new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('package', (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $name = trim((string)$request->request->get('name')) ?: 'My App';
        $package = new Package($this->user(), mb_substr($name, 0, 120));
        $this->em->persist($package);
        $this->em->flush();
        return $this->redirectToRoute('package_edit', ['id' => $package->getId()]);
    }

    #[Route('/{id}', name: 'package_edit', methods: ['GET'])]
    public function edit(string $id, Installers $installers): Response
    {
        $package = $this->mine($id);
        return $this->render('package/edit.html.twig', [
            'package' => $package,
            'files' => $this->files->list($package),
            'platforms' => $installers->available(),
            'problems' => Script::parse($package->getScript())->problems(),
        ]);
    }

    /** Reads a script (the editor, as one types): its problems with their lines. */
    #[Route('/{id}/check', name: 'package_check', methods: ['POST'])]
    public function check(string $id, Request $request): JsonResponse
    {
        $package = $this->mine($id);
        $script = Script::parse($this->body($request)['script'] ?? '');
        $missing = [];
        foreach (self::filesOf($script) as $file) {
            if ($this->files->file($package, $file) === null) {
                $missing[] = $file;
            }
        }
        return $this->json(['problems' => $script->problems(), 'missing' => $missing]);
    }

    #[Route('/{id}/save', name: 'package_save', methods: ['POST'])]
    public function save(string $id, Request $request): JsonResponse
    {
        $package = $this->mine($id);
        $body = $this->body($request);
        $package->setScript((string)($body['script'] ?? ''));
        if (array_key_exists('published', $body)) {
            $script = Script::parse($package->getScript());
            if ($body['published'] && !$script->isValid()) {
                return $this->json(['error' => 'fix the problems of the script before publishing it', 'problems' => $script->problems()], 422);
            }
            $package->setPublished((bool)$body['published']);
        }
        $this->em->flush();
        return $this->json(['saved' => true, 'name' => $package->getName(), 'published' => $package->isPublished(), 'problems' => Script::parse($package->getScript())->problems()]);
    }

    #[Route('/{id}/delete', name: 'package_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $package = $this->mine($id);
        if (!$this->isCsrfTokenValid('delete' . $id, (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->files->removeAll($package);
        $this->em->remove($package);
        $this->em->flush();
        return $this->redirectToRoute('packages');
    }

    #[Route('/{id}/files', name: 'package_files', methods: ['POST'])]
    public function upload(string $id, Request $request): JsonResponse
    {
        $package = $this->mine($id);
        $this->body($request, false);
        $added = [];
        try {
            foreach ($request->files->all('files') as $upload) {
                $added[] = $this->files->add($package, $upload, (string)$request->request->get('folder', ''));
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'files' => $this->files->list($package)], 422);
        }
        return $this->json(['added' => $added, 'files' => $this->files->list($package)]);
    }

    #[Route('/{id}/files/remove', name: 'package_file_remove', methods: ['POST'])]
    public function removeFile(string $id, Request $request): JsonResponse
    {
        $package = $this->mine($id);
        $this->files->remove($package, (string)($this->body($request)['path'] ?? ''));
        return $this->json(['files' => $this->files->list($package)]);
    }

    #[Route('/{id}/files/{path}', name: 'package_file', methods: ['GET'], requirements: ['path' => '.+'])]
    public function packageFile(string $id, string $path): Response
    {
        return new BinaryFileResponse($this->files->file($this->mine($id), $path) ?? throw $this->createNotFoundException());
    }

    /** The script being edited, for the preview (kept in the session until saved). */
    #[Route('/{id}/draft', name: 'package_draft', methods: ['POST'])]
    public function draft(string $id, Request $request): JsonResponse
    {
        $this->mine($id);
        $request->getSession()->set("draft.$id", (string)($this->body($request)['script'] ?? ''));
        return $this->json(['ok' => true]);
    }

    /** The installer's own page (its ui/), with a pretend window.jumphp: what users will see. */
    #[Route('/{id}/preview', name: 'package_preview', methods: ['GET'])]
    public function preview(string $id): Response
    {
        $this->mine($id);
        $html = (string)file_get_contents($this->getParameter('kernel.project_dir') . '/public/installer-ui/index.html');
        $html = str_replace(
            ['href="style.css"', '<script src="app.js"></script>'],
            ['href="/installer-ui/style.css"', '<script>window.J2I_VIEW = ' . json_encode($this->generateUrl('package_view', ['id' => $id])) . ';</script>'
                . '<script src="/preview.js"></script><script src="/installer-ui/app.js"></script>'],
            $html,
        );
        return new Response($html, 200, ['Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'"]);
    }

    /** What the installer would show (the draft, else the saved script), as a computer of ?os= would see it. */
    #[Route('/{id}/view', name: 'package_view', methods: ['GET'])]
    public function view(string $id, Request $request): JsonResponse
    {
        $package = $this->mine($id);
        $yaml = (string)$request->getSession()->get("draft.$id", $package->getScript());
        $script = Script::parse($yaml);
        if (!$script->isValid()) {
            return $this->json(['mode' => 'error', 'error' => "The install script has problems:\n" . implode("\n", array_map('strval', $script->problems()))]);
        }
        $vars = Vars::example((string)$request->query->get('os', 'windows'));
        $session = Session::of(new InstallerPackage($package->getId(), $script, $this->files->dir($package) . '/'), $vars->with(['temp' => sys_get_temp_dir()]));
        if (!$session->wait()) {
            return $this->json(['mode' => 'error', 'error' => (string)$session->error, 'retry' => true]);
        }
        $view = $session->view();
        $stepVars = $vars->withApp($script->toArray()['app'])->with(['version' => $session->version, 'folder' => $session->defaultFolder()]);
        $view['steps'] = array_map(static fn (array $s): array => ['label' => Runner::label($s, $stepVars), 'if' => $s['if'] ?? null, 'os' => $s['os'] ?? null],
            $script->toArray()['steps']);
        return $this->json(['mode' => 'install'] + $view);
    }

    /** @return list<string> the package's own files a script uses (icon, banner, license, relative downloads, copies) */
    public static function filesOf(Script $script): array
    {
        $data = $script->toArray();
        $files = [];
        foreach (['icon', 'banner'] as $k) {
            $files[] = $data['app'][$k] ?? null;
        }
        foreach ($data['pages'] ?? [] as $page) {
            $files[] = $page['type'] === 'license' ? $page['file'] : null;
        }
        foreach ($data['steps'] ?? [] as $step) {
            $files[] = match ($step['action']) {
                'download' => $step['url'],
                'copy' => $step['from'],
                default => null,
            };
        }
        return array_values(array_unique(array_filter($files, static fn (?string $f): bool => $f !== null && $f !== ''
            && !preg_match('#^(https?://|/|[A-Za-z]:)#', $f) && !str_contains($f, '{'))));
    }

    private function mine(string $id): Package
    {
        $package = $this->packages->find($id);
        if ($package === null || $package->getOwner() !== $this->user()) {
            throw $this->createNotFoundException();
        }
        return $package;
    }

    private function user(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);
        return $user;
    }

    /** The JSON body of an editor call: they carry X-Requested-With (a header other sites cannot send: no CSRF). */
    private function body(Request $request, bool $json = true): array
    {
        if ($request->headers->get('X-Requested-With') !== 'jumphp2installer') {
            throw $this->createAccessDeniedException('editor calls only');
        }
        return $json ? (array)json_decode($request->getContent(), true) : [];
    }
}
