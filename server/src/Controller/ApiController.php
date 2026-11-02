<?php

namespace App\Controller;

use App\Entity\InstallEvent;
use App\Entity\Package;
use App\Repository\InstallEventRepository;
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
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use JumPHP2Installer\StatsReport;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What installers and visitors reach, without an account (docs/installer/server.md):
 *   GET /api/packages/{id}                 the package: {id, name, script, assets}
 *   GET /api/packages/{id}/assets/{path}   one of its files
 *   POST /api/packages/{id}/stats          what an installer of it tells (JumPHP2Installer\Stats; docs/installer/stats.md)
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

    /** One event of an installer: kept (StatsReport::accept()) for the package's dashboard. 204 always, but for nonsense. */
    #[Route('/api/packages/{id}/stats', name: 'api_stats', methods: ['POST', 'OPTIONS'], requirements: ['id' => '[a-z0-9]{4,16}'])]
    public function stats(string $id, Request $request, InstallEventRepository $events, EntityManagerInterface $em,
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache): Response
    {
        $headers = ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type'];
        if ($request->isMethod('OPTIONS')) {
            return new Response('', 204, $headers);
        }
        $package = $this->packages->find($id);
        if ($package === null || !$package->isPublished()) {
            return new Response('', 404, $headers);
        }
        $row = strlen($request->getContent()) <= 4096 ? StatsReport::accept(json_decode($request->getContent(), true)) : null;
        if ($row === null) {
            return new Response('not an installer event', 400, $headers + ['Content-Type' => 'text/plain']);
        }
        // a run sends a few dozen events, a computer a few hundred an hour: more is not an installer
        $hour = $cache->getItem('stats-' . hash('sha256', $request->getClientIp() . '|' . date('YmdH')));
        $sent = (int)$hour->get();
        if ($sent >= 600 || $events->countOfInstall($row['install']) >= 200) {
            return new Response('', 429, $headers);
        }
        $cache->save($hour->set($sent + 1)->expiresAfter(3600));
        $em->persist(new InstallEvent($package, $row));
        $em->flush();
        return new Response('', 204, $headers);
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
            $em->persist(InstallEvent::download($package, $platform));    // the dashboard's "generated, downloaded"
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
        return $this->render('package/page.html.twig', ['package' => $package, 'meta' => $script['app'] ?? [], 'platforms' => $installers->available()]);
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
