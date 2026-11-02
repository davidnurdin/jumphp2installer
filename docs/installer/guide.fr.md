# jumphp2installer : le guide

jumphp2installer fabrique des **installeurs natifs** (Windows, macOS, Linux) pour un logiciel, à partir d'un
**script YAML lisible**. Un seul exécutable sert à tous les paquets : le serveur y inscrit son adresse et l'identifiant
du paquet au moment du téléchargement (l'identifiant est aussi dans le nom du fichier : `Mon-Appli-setup-k2q9x7a1.exe`).
L'installeur télécharge **toujours la dernière version**, affiche vos images, la licence, le choix du dossier, des
options, une barre de progression, puis crée les raccourcis et un désinstalleur. Aucun droit administrateur.

## Trois façons de l'héberger

| | |
|---|---|
| **jumphp.net** | connecté › Développeur › Installeurs : éditeur, aperçu, liens de téléchargement |
| **Votre serveur** | `docker compose -f server/compose.yaml up -d --build` (Symfony + MariaDB), puis `bin/console app:user:create vous@exemple.fr` ([server.md](server.md)) |
| **Sans serveur : GitHub** | `install.yaml` et ses fichiers dans les releases de votre dépôt, installeurs compris : `tools/github-release.sh proprio/depot dossier` ou le workflow `package.yml` ([github.md](github.md)) |

## Le script en bref

```yaml
app:
  name: Mon Appli
  publisher: Moi
  icon: icon.png                 # fichiers du paquet (onglet Fichiers de l'éditeur, ou à côté du script)
  banner: banner.png             # image de gauche : 172 x 420 (344 x 840 pour les écrans fins)
version: {github: moi/mon-appli} # la dernière release donne {version}
pages:
  - welcome: "Bienvenue dans l'installation de {app} {version}."
  - license: LICENSE.txt
  - folder: "{programs}/{app}"
  - options:
      desktop: Un raccourci sur le bureau
  - install
  - finish: {run: "{folder}/mon-appli{exe}"}
steps:
  - download: https://github.com/moi/mon-appli/releases/download/v{version}/mon-appli-{platform}.zip
  - extract
  - shortcut: {target: "{folder}/mon-appli{exe}", where: desktop}
    if: desktop
  - uninstaller
```

Toutes les clés, pages, étapes et variables : [script.md](script.md) (en anglais). L'éditeur signale chaque erreur avec
sa ligne pendant la frappe ; sans serveur : `jumphp2installer-linux-x86_64 --check --script=install.yaml`.

## Pour les administrateurs

```
Mon-Appli-setup.exe --silent --folder="D:\Apps\Mon Appli" --no-option=desktop
"%LOCALAPPDATA%\Programs\Mon Appli\uninstall.exe" --uninstall --silent
```

## Statistiques

Chaque installeur dit à l'adresse de statistiques de son paquet ce qui se passe : lancé, pages vues, annulé (sur
quelle page ou pendant quelle étape), échoué (l'étape et l'erreur, sans les dossiers de l'utilisateur), installé,
appli lancée à la fin, désinstallé. Le tableau de bord du paquet (serveur : **Statistics** ; jumphp.net : **Studio ›
Installeurs › Statistiques**) montre les téléchargements, jusqu'où vont les gens, les échecs, par système, version et
jour. Rien de personnel ; l'utilisateur peut refuser (`--no-stats`), le script peut n'en envoyer aucune
(`stats: false`) ou les envoyer ailleurs (`stats: https://…`, utile sans serveur). Détails : [stats.md](stats.md).

## Un jeu ou une appli JumPHP

`bin/jumphp app:export mon-appli --linux --windows --macos` (ou `export:standalone` pour un jeu) donne un exécutable
par système : joignez-les à chaque release GitHub, puis partez de [examples/jumphp-app.yaml](examples/jumphp-app.yaml).

## Limites actuelles

Installeurs non signés (avertissement SmartScreen / Gatekeeper la première fois), pas d'installation pour tous les
utilisateurs (Program Files), pas de Linux arm64 ni de mobile, textes de l'assistant en anglais et en français.
