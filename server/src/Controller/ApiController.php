<?php

namespace App\Controller;

use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Service\Installers;
use App\Service\PackageFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What installers and visitors reach, without an account (docs/api.md):
 *   GET /api/packages/{id}                 the package: {id, name, script, assets}
 *   GET /api/packages/{id}/assets/{path}   one of its files
 *   GET /download/{id}/{platform}          its installer (windows-x86_64, macos, linux-x86_64)
 *   GET /p/{id}                            its download page
 * Only published packages (their owner sees theirs before: the editor's "Try" links).
 */
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly PackageRepository $packages,
        #[Autowire('%env(default::JUMPHP2INSTALLER_PUBLIC_URL)%')] private readonly ?string $publicUrl = null,
    ) {
    }

    #[Route('/api/packages/{id}', name: 'api_package', methods: ['GET'], requirements: ['id' => '[a-z0-9]{4,16}'])]
    public function package(string $id, Request $request): JsonResponse
    {
        $package = $this->visible($id);
        $response = new JsonResponse([
            'id' => $package->getId(),
            'name' => $package->getName(),
            'script' => $package->getScript(),
            'assets' => $this->server($request) . '/api/packages/' . $package->getId() . '/assets/',
            'updated' => $package->getUpdatedAt()->format(DATE_ATOM),
        ]);
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }

    #[Route('/api/packages/{id}/assets/{path}', name: 'api_asset', methods: ['GET'], requirements: ['id' => '[a-z0-9]{4,16}', 'path' => '.+'])]
    public function asset(string $id, string $path, PackageFiles $files): Response
    {
        $file = $files->file($this->visible($id), $path) ?? throw $this->createNotFoundException();
        $response = new BinaryFileResponse($file);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/download/{id}/{platform}', name: 'download', methods: ['GET'], requirements: ['id' => '[a-z0-9]{4,16}', 'platform' => 'windows-x86_64|macos|linux-x86_64'])]
    public function download(string $id, string $platform, Request $request, Installers $installers, EntityManagerInterface $em): Response
    {
        $package = $this->visible($id);
        try {
            [$file, $name] = $installers->make($package, $platform, $this->server($request));
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage(), 503, ['Content-Type' => 'text/plain']);
        }
        if ($package->isPublished()) {
            $package->countDownload();
            $em->flush();
        }
        $response = new BinaryFileResponse($file);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Type', 'application/octet-stream');
        return $response;
    }

    #[Route('/p/{id}', name: 'package_page', methods: ['GET'], requirements: ['id' => '[a-z0-9]{4,16}'])]
    public function page(string $id, Installers $installers): Response
    {
        $package = $this->visible($id);
        $script = \JumPHP2Installer\Script::parse($package->getScript())->toArray();
        return $this->render('package/page.html.twig', ['package' => $package, 'app' => $script['app'] ?? [], 'platforms' => $installers->available()]);
    }

    private function visible(string $id): Package
    {
        $package = $this->packages->find($id);
        if ($package === null || (!$package->isPublished() && $package->getOwner() !== $this->getUser())) {
            throw $this->createNotFoundException('no such package');
        }
        return $package;
    }

    /** The address installers come back to: JUMPHP2INSTALLER_PUBLIC_URL, else this request's. */
    private function server(Request $request): string
    {
        return rtrim($this->publicUrl ?: $request->getSchemeAndHttpHost() . $request->getBasePath(), '/');
    }
}
