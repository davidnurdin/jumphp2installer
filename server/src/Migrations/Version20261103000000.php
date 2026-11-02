<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What installers tell (statistics) and the downloads, one row each';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE install_event (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, created_at DATETIME NOT NULL, event VARCHAR(12) NOT NULL, install VARCHAR(32) NOT NULL, os VARCHAR(16) NOT NULL, arch VARCHAR(16) NOT NULL, version VARCHAR(40) NOT NULL, installer VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, page VARCHAR(40) DEFAULT NULL, step VARCHAR(40) DEFAULT NULL, error VARCHAR(300) DEFAULT NULL, seconds DOUBLE PRECISION DEFAULT NULL, package_id VARCHAR(16) NOT NULL, INDEX IDX_5F0D7928F44CABFF (package_id), INDEX package_day (package_id, day), INDEX install_id (install), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE install_event ADD CONSTRAINT FK_5F0D7928F44CABFF FOREIGN KEY (package_id) REFERENCES package (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE install_event DROP FOREIGN KEY FK_5F0D7928F44CABFF');
        $this->addSql('DROP TABLE install_event');
    }
}
