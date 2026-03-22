# Spar-Rechner

Autarke PHP/MySQL-Webapplikation zum Verwalten von Sparzielen mit Registrierung, Login, Monatslogik, automatischer und einmaliger Sparsumme, intelligenter Verteilung, Überzahlungs-Weiterverteilung und Guthaben.

## Features

- Registrierung und Login
- Passwortspeicherung per Hash
- CSRF-Schutz für Formulare
- Beliebig viele Sparziele pro Benutzer
- Neues Sparziel startet immer am ersten Tag des nächsten Monats
- Automatische monatliche Sparsumme
- Änderungen der automatischen Sparsumme gelten ab dem nächsten Monat
- Slider zur Aufteilung zwischen absoluter und relativer Verteilung
- Einmal-Sparsumme für den aktuellen Monat
- Überzahlungslogik mit automatischer Weiterverteilung
- Guthaben, wenn keine offenen Sparziele mehr vorhanden sind
- Guthaben wird automatisch verrechnet, sobald wieder offene Ziele existieren
- Bearbeiten und Löschen von Sparzielen
- Responsive Dashboard-Oberfläche
- Fortschrittsbalken pro Sparziel
- Prognose, in welchem Monat und Jahr ein Ziel erreicht wird

## Voraussetzungen

- PHP 8.1 oder neuer
- MySQL 8.0 oder neuer
- Webserver wie Apache oder Nginx
- Aktivierte PHP-Erweiterungen:
  - PDO
  - pdo_mysql
  - session

## Projektstruktur

```text
spar-rechner/
├─ index.php
├─ config.php
├─ functions.php
├─ auth.php
├─ register.php
├─ login.php
├─ logout.php
├─ dashboard.php
├─ goal_edit.php
├─ goal_delete.php
├─ payment_delete.php
├─ monthly_run.php
├─ install.sql
├─ style.css
└─ README.md
