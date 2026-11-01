<?php

namespace App\Entity;

use App\Repository\PackageRepository;
use Doctrine\ORM\Mapping as ORM;
use JumPHP2Installer\Script;

/**
 * A package: an install script (YAML) and its files (on the disk: PackageFiles). Its id is public: it is in the
 * installers' file names and in the API's addresses.
 */
#[ORM\Entity(repositoryClass: PackageRepository::class)]
class Package
{
    public const DEFAULT_SCRIPT = <<<'YAML'
        # The install script: docs/script.md. {app}, {version}, {folder}, {programs}, {platform}, {exe}... are variables.
        installer: 1

        app:
          name: My App
          publisher: Me
          # icon: icon.png           # files of this package (Files, on the right)
          # banner: banner.png       # 172 x 420 (or 344 x 840)

        version:
          latest: https://api.github.com/repos/OWNER/REPO/releases/latest

        pages:
          - welcome
          # - license: LICENSE.txt
          - folder: "{programs}/{app}"
          - options:
              desktop: A shortcut on the desktop
          - install
          - finish: {run: "{folder}/myapp{exe}"}

        steps:
          - download: https://github.com/OWNER/REPO/releases/download/v{version}/myapp-{platform}.zip
          - extract: {strip: 0}
          - executable: "{folder}/myapp"
            os: [linux, macos]
          - shortcut: {target: "{folder}/myapp{exe}", where: both}
            if: desktop
          - uninstaller
        YAML;

    #[ORM\Id, ORM\Column(length: 16)]
    private string $id;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $script;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    private int $downloads = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $name)
    {
        $this->id = self::newId();
        $this->owner = $owner;
        $this->name = $name;
        $this->script = str_replace('My App', str_replace('"', '', $name), self::DEFAULT_SCRIPT);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    /** 8 letters and digits, easy to read (no 0 / o, 1 / l). */
    public static function newId(): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
        $id = '';
        for ($i = 0; $i < 8; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getScript(): string
    {
        return $this->script;
    }

    /** Saves the script; the package's name follows its app.name when it is right. */
    public function setScript(string $script): self
    {
        $this->script = $script;
        $parsed = Script::parse($script);
        if ($parsed->name() !== '') {
            $this->name = mb_substr($parsed->name(), 0, 120);
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): self
    {
        $this->published = $published;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDownloads(): int
    {
        return $this->downloads;
    }

    public function countDownload(): void
    {
        $this->downloads++;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
