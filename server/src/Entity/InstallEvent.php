<?php

namespace App\Entity;

use App\Repository\InstallEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What an installer told (JumPHP2Installer\Stats: started, a page, cancelled, failed, installed, run, uninstalled),
 * or a download of one of its installers ('download', os = the platform). Nothing personal: StatsReport::accept().
 */
#[ORM\Entity(repositoryClass: InstallEventRepository::class)]
#[ORM\Index(name: 'package_day', columns: ['package_id', 'day'])]
#[ORM\Index(name: 'install_id', columns: ['install'])]
class InstallEvent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Package $package;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 12)]
    private string $event;

    #[ORM\Column(length: 32)]
    private string $install = '';

    #[ORM\Column(length: 16)]
    private string $os = '';

    #[ORM\Column(length: 16)]
    private string $arch = '';

    #[ORM\Column(length: 40)]
    private string $version = '';

    #[ORM\Column(length: 16)]
    private string $installer = '';

    #[ORM\Column(length: 8)]
    private string $mode = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $page = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $step = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?float $seconds = null;

    /** @param array<string, mixed> $row StatsReport::accept()'s */
    public function __construct(Package $package, array $row)
    {
        $this->package = $package;
        $this->createdAt = new \DateTimeImmutable();
        $this->day = $this->createdAt->setTime(0, 0);
        foreach (\JumPHP2Installer\StatsReport::KEPT as $field) {
            if (property_exists($this, $field) && array_key_exists($field, $row) && $row[$field] !== null) {
                $this->$field = $field === 'seconds' ? (float)$row[$field] : (string)$row[$field];
            }
        }
    }

    public static function download(Package $package, string $platform): self
    {
        return new self($package, ['event' => 'download', 'os' => $platform]);
    }
}
