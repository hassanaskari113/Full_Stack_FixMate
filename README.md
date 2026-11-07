# FixMate

A service marketplace where **customers** post jobs, **verified providers** send offers, and accepted offers become **bookings** that can be completed and reviewed.
PHP/MySQL reimplementation of the original Django FixMate project.
**Course:** Web Technologies · **Students:** Syed Hassan Askari (BCSF24M029), Saad Mujib (BCSF24M042) · **Submitted to:** Sir Numan Shafi

## Stack
HTML5 · CSS3 · vanilla JavaScript · core PHP 8.3 (no frameworks) · MySQL 8 (PDO, prepared statements) · PHP sessions · Apache on Ubuntu.

## Requirements
Ubuntu, Apache 2.4 (`mod_rewrite`, `mod_headers`, `mod_php`), PHP 8.1+ (`pdo_mysql`, `mbstring`, `fileinfo`, `gd`), **MySQL 8.0.16+** (CHECK constraints are enforced from this version).

## Installation
```bash
# 1. Project folder (document root is public/)
sudo mkdir -p /var/www/fixmate && sudo chown -R $USER:www-data /var/www/fixmate
# copy the project files in, then:
sudo chgrp -R www-data /var/www/fixmate
sudo find /var/www/fixmate -type d -exec chmod g+s {} \;
sudo chmod -R 775 /var/www/fixmate/public/uploads

# 2. Database
sudo mysql -e "CREATE DATABASE fixmate_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fixmate_user'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';
GRANT ALL PRIVILEGES ON fixmate_db.* TO 'fixmate_user'@'localhost'; FLUSH PRIVILEGES;"

# 3. Credentials (never committed)
cp config/database.example.php config/database.php   # then edit it
chmod 640 config/database.php && sudo chgrp www-data config/database.php

# 4. Import schema and sample data
mysql -u fixmate_user -p fixmate_db < database/schema.sql      # WARNING: drops and recreates all tables
mysql -u fixmate_user -p fixmate_db < database/seed.sql        # categories, services, areas

# 5. PHP upload limits
sudo bash -c 'printf "upload_max_filesize=4M\npost_max_size=24M\nmax_file_uploads=10\n" > /etc/php/8.3/apache2/conf.d/99-fixmate.ini'

# 6. Apache virtual host (/etc/apache2/sites-available/fixmate.conf)
#    ServerName fixmate.local, DocumentRoot /var/www/fixmate/public, AllowOverride All
sudo a2ensite fixmate.conf && sudo systemctl reload apache2
echo "127.0.0.1 fixmate.local" | sudo tee -a /etc/hosts

# 7. Admin account (terminal only, never via the website)
php database/create_admin.php
```
Open **http://fixmate.local**. Log in at `/login.php` with the admin email to reach `/admin/dashboard.php`.
No external services are used. The original Django `firebase_uid` field is intentionally not part of this implementation.

## Database (13 tables)
`users`, `service_categories`, `services`, `service_areas`, `provider_profiles`, `provider_profile_services` (M2M), `provider_profile_service_areas` (M2M), `service_requests`, `request_images`, `offers`, `bookings`, `reviews`, `review_images`.

| Relationship | Type |
|---|---|
| users - provider_profiles | 1 : 1 |
| service_categories - services | 1 : N |
| service_categories - service_requests | 1 : N |
| users (customer) - service_requests | 1 : N |
| service_requests - request_images | 1 : N |
| service_requests - offers | 1 : N (UNIQUE per provider) |
| provider_profiles - offers | 1 : N |
| offers - bookings | 1 : 1 |
| bookings - reviews | 1 : 1 |
| reviews - review_images | 1 : N |
| provider_profiles - services | N : M |
| provider_profiles - service_areas | N : M |

Every Django `CASCADE` is an `ON DELETE CASCADE` foreign key. Enforced in the database: unique username/email/phone/names, `price > 0`, `final_price > 0`, `rating 1-5`, status value lists, one offer per provider per request, one booking per offer, one review per booking.

**Differences from the Django models:** `is_admin` replaces `is_staff/is_superuser`; `email` is required and unique (login identifier); `firebase_uid` omitted; choice fields use CHECK constraints; images are stored as relative paths with files on disk.

## Modules
- **Public:** landing, services, about, signup, login.
- **Customer:** dashboard, post request (up to 5 images), my requests, request details with offers (accept / reject), bookings, reviews.
- **Provider:** profile setup (services and areas), verification status, matching requests, send offers, my offers, bookings, reviews received.
- **Admin:** dashboard statistics, users, provider verification, categories, services, areas, requests (expire / delete), offers, bookings, reviews.
- **Shared:** profile settings (details, picture, password).

## Business rules (decisions where the models are silent)
- Providers can send offers only when **Approved**. A rejected provider resubmits by saving the profile (back to Pending).
- **Matching:** request is Open and before its deadline, its category matches a category of one of the provider's services, and its free-text location contains one of the provider's area names (case-insensitive).
- **Accepting an offer** is one transaction: accept it, reject other pending offers, create the booking (scheduled time and price copied from the offer), close the request. Rolled back on any failure.
- Only **Confirmed** bookings can be cancelled (reason required) or completed, by either the customer or the provider of that booking.
- **Cancellation reopens** the request only if its deadline is still in the future; the cancelled offer becomes Rejected and Rejected offers return to Pending. Otherwise the request stays Closed.
- Only the customer of a **Completed** booking can review it, once, with a 1-5 rating and up to 5 images.
- Admin **expiry** sets Open requests to Expired and their pending offers to Expired.

## Security
`password_hash`/`password_verify`, session ID regeneration on login, HttpOnly + SameSite cookies, idle timeout, CSRF tokens on every state-changing form (logout included), PDO prepared statements everywhere, output escaping with `htmlspecialchars`, server-side validation, role checks on every protected page, ownership checks on all private records (other users' records return 404/403), uploads validated by real MIME type with random filenames and **PHP execution disabled** in `uploads/`, transactions with row locks for multi-step operations, credentials kept outside the web root and out of git.

## Screenshots
Add screenshots of the main pages to `docs/screenshots/` before submission.
