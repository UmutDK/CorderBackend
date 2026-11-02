# API Corder

API PHP du site corder.be, en PHP pur, sans framework. Elle sert les données du frontend Angular (dépôt `CorderFrontend`).

Le contrat d'API (URL, formats, codes d'erreur) est décrit dans `docs/api-contract.md`, dans le dépôt frontend.

- PHP 8.1 ou plus récent, avec l'extension `pdo_mysql`.
- MySQL 5.7+ ou MariaDB 10.4+ (en local : Docker, voir plus bas).
- Composer en local.

## Organisation

```
public/          → seul dossier exposé au web (public_html/api sur cPanel)
  index.php      → point d'entrée : CORS, routeur, gestion des erreurs
routes.php       → liste de toutes les routes
src/
  Controllers/   → un contrôleur par ressource (articles, formations, contenus, contact, newsletter)
  Http/          → Request, Response, Router, HttpException
  Database.php   → connexion PDO et petites fonctions de requête
  Validator.php  → validation des formulaires (erreurs 422 par champ)
  Mailer.php     → e-mails (PHPMailer en SMTP, ou fichier de log en développement)
  RateLimiter.php, HtmlSanitizer.php, Dates.php, Env.php
database/
  schema.sql     → tables
  seed.php       → crée les tables et importe les données d'exemple du frontend
storage/logs/    → php-error.log, mail.log (développement)
```

## Installation en local

La base de données tourne dans **Docker** (MariaDB 10.6 et phpMyAdmin). L'API tourne directement avec le serveur intégré de PHP, sans Docker. Il faut donc PHP 8.1+ (celui de XAMPP convient) et Docker Desktop.

```bash
composer install
cp .env.example .env               # déjà réglé pour la base Docker
docker compose up -d               # base sur le port 3307, phpMyAdmin sur http://localhost:8081
php database/seed.php              # données d'exemple (lit ../frontend/public/data)
php -S localhost:8000 -t public public/index.php    # API sur http://localhost:8000 (raccourci : composer serve)
```

Ensuite, dans le dépôt frontend : `npm run start:api`. Le site tourne sur http://localhost:4200 et lit l'API locale.

Commandes utiles :

- `docker compose stop` arrête la base. Les données sont conservées.
- `docker compose down -v` supprime la base. Au prochain `up`, les tables sont recréées vides, il faut relancer `seed.php`.
- Le port 3307 évite le conflit avec un MySQL de XAMPP, qui occupe le 3306.

Pour accéder à l'administration (http://localhost:4200/admin), créez un compte :

```bash
php database/create-admin.php prenom.nom@corder.be "Prénom Nom"
```

Le mot de passe est généré et affiché une seule fois. En développement, on peut aussi choisir son mot de passe en l'ajoutant en 3ᵉ argument : 12 caractères minimum. Relancer la commande avec la même adresse réinitialise le mot de passe.

Les e-mails ne sont pas envoyés en local : ils sont écrits dans `storage/logs/mail.log` (`MAIL_DRIVER=log`).

`seed.php` **vide les tables** avant de les remplir. Il ne faut jamais le lancer sur la base de production une fois que du vrai contenu y a été saisi.

## Routes

| Méthode | URL | Rôle |
|---|---|---|
| GET | `/articles?limit=&category=` | Actualités, hors alertes |
| GET | `/articles/{slug}` | Article complet (fonctionne aussi pour une alerte) |
| GET | `/alerts?limit=` | Alertes réglementaires |
| GET | `/trainings?upcoming=true` | Formations |
| GET | `/trainings/{slug}` | Fiche formation |
| POST | `/trainings/{slug}/registrations` | Inscription (409 `full` / `closed` / `already_registered`) |
| GET | `/faq`, `/key-figures`, `/team` | Contenus |
| POST | `/contact` | Formulaire de contact |
| POST | `/newsletter/subscriptions` | Inscription à la newsletter (double confirmation) |
| GET | `/newsletter/confirm?token=` | Lien de confirmation cliqué dans l'e-mail |

### Administration

Ces routes demandent une session, sauf la connexion. La session est un cookie `corder_admin` : HttpOnly, SameSite=Strict, expiré après 2 h d'inactivité. Les envois doivent être en JSON (protection CSRF). La connexion est limitée à 10 tentatives par heure et par adresse IP.

| Méthode | URL | Rôle |
|---|---|---|
| POST | `/admin/login` · `/admin/logout` | Connexion, déconnexion |
| GET | `/admin/me` | Utilisateur connecté (401 sinon) |
| GET | `/admin/stats` | Chiffres du tableau de bord |
| GET, POST | `/admin/articles` | Liste (brouillons compris), création |
| GET, PUT, DELETE | `/admin/articles/{id}` | Lecture, modification, suppression |
| GET | `/admin/categories` | Catégories d'articles |
| POST | `/admin/uploads` | Envoi d'une image (multipart, champ `file`, JPEG/PNG/WebP, 5 Mo max) |
| GET, POST | `/admin/trainings` | Formations (passées et masquées comprises), création |
| GET, PUT, DELETE | `/admin/trainings/{id}` | Lecture, modification, suppression (409 `has_registrations` s'il y a des inscrits) |
| POST | `/admin/trainings/{id}/registrations` | Inscription saisie par l'équipe (capacité vérifiée, date limite ignorée, `sendConfirmation` facultatif) |
| GET | `/admin/registrations?trainingId=` | Inscrits, les plus récents d'abord |
| GET | `/admin/registrations/export?trainingId=` | Export CSV pour Excel (séparateur `;`, UTF-8 avec BOM) |
| DELETE | `/admin/registrations/{id}` | Annulation : la place est libérée |

## Mise en ligne sur cPanel

1. **Base de données.**
   - Dans « Bases de données MySQL », créer la base et un utilisateur. cPanel ajoute un préfixe aux noms, par exemple `compte_corder`.
   - Donner tous les privilèges à l'utilisateur sur la base.
   - Importer `database/schema.sql` dans phpMyAdmin.
2. **E-mail.** Dans « Comptes de messagerie », créer `noreply@corder.be`. Les réglages SMTP (hôte, port) s'affichent dans « Connect Devices ».
3. **Fichiers.**
   - `public/*` va dans `~/public_html/api/`, y compris `.htaccess`.
   - Tout le reste va dans `~/corder-api/` : `bootstrap.php`, `routes.php`, `src/`, `vendor/`, `database/`, `storage/` et `.env`. Ce dossier est hors de `public_html`, donc il n'est pas accessible depuis le web.
   - Lancer `composer install --no-dev` en local avant l'envoi, sauf si cPanel donne accès au Terminal.
   - **Ne jamais écraser `public_html/api/uploads/`** lors d'une mise à jour : ce dossier contient les images envoyées depuis l'administration.
4. **`.env` de production.** Repartir de `.env.example` et régler :
   - `APP_ENV=production`, `APP_URL=https://corder.be`, `API_URL=https://corder.be/api` ;
   - `CORS_ORIGINS=https://corder.be,https://www.corder.be` ;
   - les accès à la base, et `MAIL_DRIVER=smtp` avec le mot de passe du compte e-mail.
5. **Version de PHP.** Dans « Select PHP Version », choisir 8.1 ou plus récent.
6. **Vérification.** `https://corder.be/api/faq` doit renvoyer du JSON.
7. **Premier compte d'administration.** Dans le Terminal de cPanel, depuis `~/corder-api` : `php database/create-admin.php …`. Sans Terminal, créer le compte en local, puis exporter la ligne de la table `admin_users` via phpMyAdmin.

Les droits d'écriture sont nécessaires sur `storage/` : journaux et cache du nettoyeur HTML.
