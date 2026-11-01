<?php

namespace App\Controller;

use App\Service\Installers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(Installers $installers): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('packages');
        }
        return $this->render('home.html.twig', ['platforms' => $installers->available()]);
    }
}
