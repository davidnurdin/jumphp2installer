<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accounts and packages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE package (id VARCHAR(16) NOT NULL, name VARCHAR(120) NOT NULL, script LONGTEXT NOT NULL, published TINYINT NOT NULL, downloads INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_DE6867957E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE package ADD CONSTRAINT FK_DE6867957E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package DROP FOREIGN KEY FK_DE6867957E3C61F9');
        $this->addSql('DROP TABLE package');
        $this->addSql('DROP TABLE app_user');
    }
}
