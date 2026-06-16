# WebMonitor

Aplicació de monitoratge de webs construïda amb Laravel 12. Permet fer seguiment continu de la disponibilitat i salut de múltiples URLs, amb alertes per correu electrònic, webhooks i pàgines d'estat públiques compartibles.

---

## Característiques principals

- **Monitoratge automàtic** — comprova les URLs cada minut (o amb l'interval configurat)
- **Detecció de canvis d'estat** — notifica per correu i webhook quan un servei cau o es recupera
- **Verificació de contingut** — cerca paraules clau a la resposta per validar que el servei funciona correctament
- **Autenticació bàsica** — suport per monitorar URLs protegides amb usuari i contrasenya
- **Pàgines d'estat públiques** — URL compartible via token únic, sense necessitat de login
- **Historial de comprovacions** — registre complet amb temps de resposta, codi HTTP i errors
- **Panell d'administració** — gestió d'usuaris i rols via Filament (`/admin`)
- **Protecció SSRF** — valida que les URLs no apuntin a IPs privades o de loopback

---

## Stack tecnològic

| Capa | Tecnologia |
|---|---|
| Backend | PHP 8.3 + Laravel 12 |
| Admin panel | Filament 3.2 |
| Frontend | Blade + Tailwind CSS + Alpine.js |
| Base de dades | MariaDB 10.11 (DDEV) / SQLite (dev) |
| Cua de treballs | Laravel Queue (driver `database`) |
| Assets | Vite 7 |
| Entorn local | DDEV |

---

## Requisits

- [DDEV](https://ddev.readthedocs.io/) 1.23+
- Docker Desktop
- Node.js 20+
- PHP 8.3 (via DDEV, no cal instal·lar localment)

---

## Instal·lació

```bash
# 1. Clona el repositori
git clone <repo-url> webmonitor
cd webmonitor

# 2. Inicia l'entorn DDEV
ddev start

# 3. Instal·la les dependències PHP i JS
ddev composer install
npm install

# 4. Configura l'entorn
cp .env.example .env
ddev artisan key:generate

# 5. Executa les migracions
ddev artisan migrate

# 6. Compila els assets
npm run build
```

L'aplicació queda disponible a **https://webmonitor.ddev.site**

---

## Execució en desenvolupament

```bash
# Inicia tots els processos en paral·lel (servidor, cua, logs, Vite)
ddev exec composer run dev
```

Això llança:
- Servidor Laravel
- Worker de la cua (`queue:listen`)
- Streaming de logs (`pail`)
- Vite en mode `--watch`

---

## Planificador de tasques

El planificador de Laravel comprova cada minut quins monitors han de llançar una nova comprovació i els envia a la cua. Per activar-lo en producció:

```bash
# Afegeix aquesta línia al crontab del servidor
* * * * * cd /ruta/al/projecte && php artisan schedule:run >> /dev/null 2>&1
```

En local, el `composer run dev` ja s'encarrega d'executar la cua.

---

## Estructura del projecte

```
webmonitor/
├── app/
│   ├── Filament/Resources/     # Panell admin (UserResource)
│   ├── Http/Controllers/       # MonitorController, PublicStatusController
│   ├── Jobs/                   # CheckMonitorJob
│   ├── Models/                 # Monitor, MonitorLog, User
│   ├── Notifications/          # MonitorStatusChanged (email)
│   ├── Policies/               # MonitorPolicy (autorització per usuari)
│   ├── Rules/                  # NoPrivateUrl (protecció SSRF)
│   └── Services/               # MonitoringService (lògica de comprovació)
├── database/
│   ├── migrations/             # Esquema de la base de dades
│   └── seeders/                # Dades inicials de prova
├── resources/views/
│   ├── monitors/               # index, create, edit, show
│   └── public-status.blade.php # Pàgina d'estat pública
├── routes/
│   ├── web.php                 # Rutes principals
│   └── console.php             # Tasca planificada (scheduler)
└── .ddev/config.yaml           # Configuració DDEV
```

---

## Rols i accés

| Rol | Permisos |
|---|---|
| `user` | Gestiona els seus propis monitors |
| `admin` | Accés al panell `/admin` + gestió d'usuaris |

El rol per defecte en registrar-se és `user`. Els administradors es creen manualment o des del panell.

---

## Base de dades

**Taules principals:**

- `users` — usuaris amb camp `role` (`admin` / `user`)
- `monitors` — configuració de cada monitor (URL, interval, timeout, codi HTTP esperat, keyword, autenticació bàsica xifrada, token públic, webhook)
- `monitor_logs` — historial de comprovacions (estat, temps de resposta, codi HTTP, error)
- `jobs` — cua de treballs per processar
- `failed_jobs` — treballs fallits per diagnòstic

---

## Variables d'entorn rellevants

```env
DB_CONNECTION=mariadb
DB_HOST=db
DB_DATABASE=db
DB_USERNAME=db
DB_PASSWORD=db

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

MAIL_MAILER=log          # Canvia per smtp/ses en producció
APP_URL=https://webmonitor.ddev.site
```

---

## Tests

```bash
ddev exec composer run test
```

---

## Seguretat

- Les contrasenyes d'autenticació bàsica es guarden xifrades a la base de dades
- La regla `NoPrivateUrl` bloqueja URLs que apuntin a xarxes privades (SSRF)
- Les pàgines d'estat públiques estan limitades a 30 peticions/minut
- Els usuaris només poden gestionar els seus propis monitors (via `MonitorPolicy`)

---

## Llicència

MIT
