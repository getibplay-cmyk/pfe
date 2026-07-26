"""Real-browser QA for RentFleet Lot 06F-E2.

The script uses the already-installed Python Playwright package and the system
Chrome/Edge binaries. It never accepts or prints a password: a random QA value
is generated in memory and applied only after a hard rentfleet_test guard.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

from playwright.sync_api import Browser, Page, Playwright, sync_playwright


ROLE_ACCOUNTS = {
    "platform-admin": "platform@rentfleet.test",
    "tenant-owner": "tenant-owner@atlas-demo.test",
    "agency-manager": "agency-manager@atlas-demo.test",
    "rental-agent": "rental-agent@atlas-demo.test",
    "fleet-manager": "fleet-manager@atlas-demo.test",
    "accountant": "accountant@atlas-demo.test",
    "viewer": "viewer-auditor@atlas-demo.test",
}

QA_ACCOUNTS = [*ROLE_ACCOUNTS.values(), "owner@rif-demo.test"]

ROLE_LABELS = {
    "platform-admin": "Administrateur de la plateforme",
    "tenant-owner": "Administrateur de l’entreprise",
    "agency-manager": "Responsable d’agence",
    "rental-agent": "Agent de location",
    "fleet-manager": "Responsable de flotte",
    "accountant": "Comptable",
    "viewer": "Lecteur / auditeur",
}

FORBIDDEN_NAVIGATION = {
    "platform-admin": {"dashboard", "reservations", "finance", "users"},
    "tenant-owner": {"platform-dashboard", "platform-tenants"},
    "agency-manager": {"tenant", "platform-dashboard", "platform-tenants"},
    "rental-agent": {"audit", "reports", "vehicles", "platform-tenants"},
    "fleet-manager": {"customers", "pricing", "reports", "users", "platform-tenants"},
    "accountant": {"audit", "customers", "insurance", "maintenance", "users", "vehicles", "platform-tenants"},
    "viewer": {"tenant", "vehicle-blocks", "platform-tenants"},
}

VIEWPORTS = {
    "desktop": {"width": 1440, "height": 900},
    "mobile": {"width": 390, "height": 844},
    "tablet": {"width": 768, "height": 1024},
    "desktop-narrow": {"width": 1024, "height": 768},
    "mobile-320": {"width": 320, "height": 720},
    "zoom-200-desktop": {"width": 720, "height": 450},
}

CHROME = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
EDGE = Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe")
DEFAULT_QA_DATABASE = "rentfleet_test"
ACCEPTANCE_QA_DATABASE = "rentfleet_06g_acceptance"


def resolve_qa_database() -> str:
    database = os.environ.get("RENTFLEET_QA_DATABASE", DEFAULT_QA_DATABASE)
    if database == DEFAULT_QA_DATABASE:
        return database
    if (
        database == ACCEPTANCE_QA_DATABASE
        and os.environ.get("RENTFLEET_ACCEPTANCE_MODE") == "1"
        and os.environ.get("APP_ENV") == "testing"
        and os.environ.get("DB_CONNECTION") == "pgsql"
    ):
        return database
    raise RuntimeError("La base QA demandée n’est pas autorisée.")


QA_DATABASE = resolve_qa_database()


class BrowserAudit:
    def __init__(self, root: Path, base_url: str, output: Path, screenshots: Path, php: Path) -> None:
        self.root = root
        self.base_url = base_url.rstrip("/")
        self.output = output
        self.screenshots = screenshots
        self.php = php
        self.password = secrets.token_urlsafe(32)
        self.active_browser = "browser"
        self.qa_agency_id: str | None = None
        self.qa_customer_id: str | None = None
        self.qa_driver_id: str | None = None
        self.results: dict[str, Any] = {
            "lot": "06F-E2",
            "generated_at": datetime.now().astimezone().isoformat(),
            "base_url": self.base_url,
            "qa_database": QA_DATABASE,
            "browsers": {},
            "viewports": VIEWPORTS,
            "checks": [],
            "page_audits": [],
            "contrast_audits": [],
            "issues": [],
            "screenshots": [],
            "limits": [],
        }
        self.server: subprocess.Popen[bytes] | None = None

    def check(self, name: str, passed: bool, details: str = "") -> None:
        self.results["checks"].append({"name": name, "passed": passed, "details": details})
        if not passed:
            self.issue("majeur", name, details or "Contrôle en échec")

    def issue(self, severity: str, area: str, details: str, route: str | None = None) -> None:
        self.results["issues"].append({
            "severity": severity,
            "area": area,
            "route": route,
            "details": details[:500],
        })

    def artisan(self, *arguments: str, env: dict[str, str], timeout: int = 90) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [str(self.php), "artisan", *arguments],
            cwd=self.root,
            env=env,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=timeout,
            check=True,
        )

    def prepare_qa(self, env: dict[str, str]) -> None:
        self.artisan("optimize:clear", "--env=testing", env=env)
        emails = ",".join(f"'{email}'" for email in QA_ACCOUNTS)
        account_guard = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            f"if($db!=='{QA_DATABASE}'){{throw new RuntimeException('QA database guard failed.');}}"
            f"$emails=[{emails}];"
            "$count=App\\Models\\User::withoutGlobalScopes()->whereIn('email',$emails)->count();"
            "echo 'qa_account_count='.$count;"
        )
        guarded = self.artisan("tinker", "--env=testing", f"--execute={account_guard}", env=env)
        account_count = re.search(r"qa_account_count=(\d+)", guarded.stdout)
        if not account_count:
            raise RuntimeError("Le garde des comptes E2 n’a pas produit de compteur.")
        if account_count.group(1) == "0":
            self.artisan("db:seed", "--env=testing", "--no-interaction", env=env, timeout=300)
        elif account_count.group(1) != str(len(QA_ACCOUNTS)):
            raise RuntimeError("Le jeu de comptes E2 est partiel ; aucune correction automatique appliquée.")

        code = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            f"if($db!=='{QA_DATABASE}'){{throw new RuntimeException('QA database guard failed.');}}"
            "$hash=Hash::make((string)getenv('E2_QA_PASSWORD'));"
            f"$emails=[{emails}];"
            "$count=App\\Models\\User::withoutGlobalScopes()->whereIn('email',$emails)"
            "->update(['password'=>$hash,'must_change_password'=>false,'is_active'=>true]);"
            "if($count!==8){throw new RuntimeException('QA role account guard failed.');}"
            "$verified=App\\Models\\User::withoutGlobalScopes()->whereIn('email',$emails)->get(['email','password'])"
            "->filter(fn($user)=>Hash::check((string)getenv('E2_QA_PASSWORD'),$user->password))->count();"
            "if($verified!==8){throw new RuntimeException('QA password verification failed.');}"
            "foreach($emails as $email){RateLimiter::clear(Str::transliterate(Str::lower($email).'|127.0.0.1'));}"
            "$eligible=DB::table('drivers as d')->join('customers as c','c.id','=','d.customer_id')"
            "->join('agencies as a','a.id','=','c.agency_id')->join('tenants as t','t.id','=','c.tenant_id')"
            "->where('t.slug','atlas-location-demo')->where('a.is_active',true)"
            "->where('c.verification_status','verified')->where('d.verification_status','verified')"
            "->whereNull('c.deleted_at')->whereNull('d.deleted_at')->whereDate('d.licence_expires_at','>',now()->addDays(150))"
            "->select('a.id as agency_id','c.id as customer_id','d.id as driver_id')->orderBy('a.id')->orderBy('c.id')->first();"
            "if(!$eligible){throw new RuntimeException('QA eligible reservation party guard failed.');}"
            "echo 'qa_setup=ok;eligible='.$eligible->agency_id.','.$eligible->customer_id.','.$eligible->driver_id;"
        )
        completed = self.artisan("tinker", "--env=testing", f"--execute={code}", env=env)
        prepared = "qa_setup=ok" in completed.stdout
        self.check("Garde base QA et huit comptes", prepared, f"{QA_DATABASE}, 8 comptes")
        if not prepared:
            raise RuntimeError(f"La préparation des comptes QA a échoué après la garde {QA_DATABASE}.")
        eligible = re.search(r"eligible=(\d+),(\d+),(\d+)", completed.stdout)
        if not eligible:
            raise RuntimeError(f"Aucun triplet agence/client/conducteur éligible n’a été confirmé dans {QA_DATABASE}.")
        self.qa_agency_id, self.qa_customer_id, self.qa_driver_id = eligible.groups()
        self.artisan("config:cache", "--env=testing", env=env)
        cached_guard = self.artisan(
            "tinker",
            "--execute=$db=DB::selectOne('select current_database() as db')->db; "
            f"if($db!=='{QA_DATABASE}'){{throw new RuntimeException('Cached QA database guard failed.');}} "
            "echo 'cached_qa=ok';",
            env=env,
        )
        if "cached_qa=ok" not in cached_guard.stdout:
            raise RuntimeError(f"La configuration HTTP en cache ne cible pas {QA_DATABASE}.")
        self.check("Configuration HTTP QA", True, f"cache vérifié sur {QA_DATABASE}")

    def start_server(self, env: dict[str, str], port: int) -> None:
        creation_flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
        router = self.root / "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
        self.server = subprocess.Popen(
            [str(self.php), "-S", f"127.0.0.1:{port}", str(router)],
            cwd=self.root / "public",
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            creationflags=creation_flags,
        )
        deadline = time.time() + 30
        while time.time() < deadline:
            if self.server.poll() is not None:
                raise RuntimeError("Le serveur QA s’est arrêté avant le contrôle navigateur.")
            try:
                with urllib.request.urlopen(f"{self.base_url}/health", timeout=2) as response:
                    if response.status == 200:
                        self.check("Serveur QA", True, f"HTTP 200 sur {self.base_url}/health")
                        return
            except (urllib.error.URLError, TimeoutError):
                time.sleep(0.4)
        raise RuntimeError("Le serveur QA n’a pas répondu dans le délai imparti.")

    def stop_server(self) -> None:
        if self.server and self.server.poll() is None:
            self.server.terminate()
            try:
                self.server.wait(timeout=8)
            except subprocess.TimeoutExpired:
                self.server.kill()

    def new_context(self, browser: Browser, viewport: dict[str, int]):
        return browser.new_context(
            viewport=viewport,
            locale="fr-FR",
            timezone_id="Africa/Casablanca",
            color_scheme="light",
            reduced_motion="reduce",
        )

    def goto(self, page: Page, path: str, expected: tuple[int, ...] = (200,)):
        response = page.goto(f"{self.base_url}{path}", wait_until="networkidle")
        status = response.status if response else 0
        self.check(f"HTTP {path}", status in expected, f"statut={status}")
        return response

    def login(self, page: Page, role: str, keyboard: bool = False) -> None:
        self.goto(page, "/login")
        email = page.locator('input[name="email"]')
        password = page.locator('input[name="password"]')
        if keyboard:
            email.focus()
            page.keyboard.type(ROLE_ACCOUNTS[role])
            page.keyboard.press("Tab")
            self.check("Ordre clavier login vers mot de passe", password.evaluate("el => el === document.activeElement"), role)
            page.keyboard.type(self.password)
            with page.expect_navigation(wait_until="networkidle"):
                page.keyboard.press("Enter")
        else:
            email.fill(ROLE_ACCOUNTS[role])
            password.fill(self.password)
            with page.expect_navigation(wait_until="networkidle"):
                page.get_by_role("button", name="Se connecter").click()
        expected = "/platform/dashboard" if role == "platform-admin" else "/dashboard"
        arrived = page.url.removeprefix(self.base_url)
        if not page.url.endswith(expected):
            alerts = " ".join(page.locator('[role="alert"]').all_inner_texts()).strip()
            arrived = f"arrivée={arrived}; erreur_affichée={bool(alerts)}"
        self.check(f"Connexion {role}", page.url.endswith(expected), arrived)

    def screenshot(self, page: Page, name: str) -> None:
        path = self.screenshots / f"{self.active_browser}-{name}"
        page.screenshot(path=path, full_page=False, animations="disabled")
        try:
            recorded = path.relative_to(self.root)
        except ValueError:
            recorded = path.relative_to(self.output.parent)
        self.results["screenshots"].append(str(recorded).replace("\\", "/"))

    def audit_contrast(self, page: Page, label: str, browser_name: str, viewport: str) -> None:
        result = page.evaluate(
            """() => {
                const visible = (el) => {
                    const style = getComputedStyle(el);
                    const rect = el.getBoundingClientRect();
                    return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0'
                        && rect.width > 0 && rect.height > 0;
                };
                const rgb = (value) => {
                    const match = value.match(/rgba?\\((\\d+(?:\\.\\d+)?)[, ]+(\\d+(?:\\.\\d+)?)[, ]+(\\d+(?:\\.\\d+)?)(?:[, /]+(\\d+(?:\\.\\d+)?))?\\)/);
                    return match ? {r: +match[1], g: +match[2], b: +match[3], a: match[4] === undefined ? 1 : +match[4]} : null;
                };
                const background = (el) => {
                    let current = el;
                    while (current) {
                        const style = getComputedStyle(current);
                        if (style.backgroundImage && style.backgroundImage !== 'none') return null;
                        const color = rgb(style.backgroundColor);
                        if (color && color.a >= 0.99) return color;
                        current = current.parentElement;
                    }
                    return {r: 255, g: 255, b: 255, a: 1};
                };
                const luminance = ({r, g, b}) => {
                    const channel = (value) => {
                        const normalized = value / 255;
                        return normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
                    };
                    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
                };
                const ratio = (foreground, backgroundColor) => {
                    const first = luminance(foreground);
                    const second = luminance(backgroundColor);
                    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
                };
                const selectors = 'h1, h2, h3, p, label, a[href], button, th, td, [role=status], [role=alert]';
                const candidates = [...document.querySelectorAll(selectors)].filter(visible).filter(el => (el.innerText || '').trim());
                const samples = [];
                const seen = new Set();
                for (const el of candidates) {
                    const style = getComputedStyle(el);
                    const foreground = rgb(style.color);
                    const backgroundColor = background(el);
                    if (!foreground || foreground.a < 0.99 || !backgroundColor) continue;
                    const fontSize = parseFloat(style.fontSize);
                    const weight = parseInt(style.fontWeight, 10) || 400;
                    const large = fontSize >= 24 || (fontSize >= 18.66 && weight >= 700);
                    const value = ratio(foreground, backgroundColor);
                    const key = [style.color, backgroundColor.r, backgroundColor.g, backgroundColor.b, fontSize, weight].join('|');
                    if (seen.has(key)) continue;
                    seen.add(key);
                    samples.push({
                        element: el.tagName.toLowerCase(),
                        text: (el.innerText || '').trim().replace(/\\s+/g, ' ').slice(0, 60),
                        foreground: style.color,
                        background: `rgb(${backgroundColor.r}, ${backgroundColor.g}, ${backgroundColor.b})`,
                        fontSize,
                        weight,
                        ratio: Math.round(value * 100) / 100,
                        required: large ? 3 : 4.5,
                        passed: value + 0.01 >= (large ? 3 : 4.5),
                    });
                    if (samples.length >= 80) break;
                }
                return {path: location.pathname, samples, violations: samples.filter(item => !item.passed)};
            }"""
        )
        result.update({"label": label, "browser": browser_name, "viewport": viewport})
        self.results["contrast_audits"].append(result)
        if result["violations"]:
            self.issue("majeur", "contrastes", f"paires sous seuil={result['violations']}", result["path"])

    def audit_page(self, page: Page, label: str, browser_name: str, viewport: str, zoom: str = "100%") -> dict[str, Any]:
        payload = page.evaluate(
            """() => {
                const visible = (el) => {
                    const style = getComputedStyle(el);
                    const rect = el.getBoundingClientRect();
                    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
                };
                const controlName = (el) => ({tag: el.tagName.toLowerCase(), type: el.type || null, id: el.id || null, name: el.name || null});
                const controls = [...document.querySelectorAll('input:not([type=hidden]), select, textarea')].filter(visible);
                const unlabeled = controls.filter((el) => {
                    if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.closest('label')) return false;
                    return !(el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`));
                }).map(controlName);
                const interactive = [...document.querySelectorAll('button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=menuitem]')].filter(visible);
                const accessibleName = (el) => {
                    const labels = el.labels ? [...el.labels].map(label => label.innerText).join(' ') : '';
                    return (el.innerText || el.value || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.title || labels || '').trim();
                };
                const unnamed = interactive.filter((el) => !accessibleName(el)).map(controlName);
                const ids = [...document.querySelectorAll('[id]')].map(el => el.id).filter(Boolean);
                const duplicates = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
                const remote = [...document.querySelectorAll('script[src], link[href], img[src]')]
                    .map(el => el.src || el.href).filter(Boolean)
                    .filter(url => { try { return new URL(url, location.href).origin !== location.origin; } catch { return true; } });
                const tables = [...document.querySelectorAll('table')].filter(visible).map((table) => {
                    const parent = table.parentElement;
                    return {
                        headers: table.querySelectorAll('th').length,
                        scrollable: !!table.closest('.overflow-x-auto, .rf-table-scroll') || (parent && ['auto','scroll'].includes(getComputedStyle(parent).overflowX)),
                        overflow: table.scrollWidth > table.clientWidth,
                    };
                });
                const sensitiveMarkers = ['DB_PASSWORD','APP_KEY','SQLSTATE','storage/app/private','BEGIN PRIVATE KEY']
                    .filter(marker => document.body.innerText.includes(marker));
                const unclippedOverflow = [...document.querySelectorAll('body *')].filter(visible).filter((el) => {
                    const rect = el.getBoundingClientRect();
                    if (rect.right <= innerWidth + 1 && rect.left >= -1) return false;
                    let parent = el.parentElement;
                    while (parent && parent !== document.body) {
                        const overflow = getComputedStyle(parent).overflowX;
                        if (['auto', 'scroll', 'hidden', 'clip'].includes(overflow)) return false;
                        parent = parent.parentElement;
                    }
                    return true;
                }).slice(0, 12).map((el) => {
                    const rect = el.getBoundingClientRect();
                    return {
                        tag: el.tagName.toLowerCase(),
                        classes: String(el.className || '').slice(0, 140),
                        text: (el.innerText || '').trim().replace(/\\s+/g, ' ').slice(0, 60),
                        left: Math.round(rect.left),
                        right: Math.round(rect.right),
                        width: Math.round(rect.width),
                    };
                });
                const smallTargets = interactive.map((el) => {
                    const r = el.getBoundingClientRect();
                    return {tag: el.tagName.toLowerCase(), name: (el.innerText || el.getAttribute('aria-label') || '').trim().slice(0,40), width: Math.round(r.width), height: Math.round(r.height)};
                }).filter(item => item.width < 24 || item.height < 24).slice(0,20);
                const initialScrollX = window.scrollX;
                window.scrollTo({left: 100000, top: window.scrollY, behavior: 'instant'});
                const windowOverflow = window.scrollX;
                window.scrollTo({left: initialScrollX, top: window.scrollY, behavior: 'instant'});
                return {
                    path: location.pathname,
                    lang: document.documentElement.lang,
                    title: document.title,
                    h1: [...document.querySelectorAll('h1')].filter(visible).map(el => el.innerText.trim()),
                    main: document.querySelectorAll('main').length,
                    nav: document.querySelectorAll('nav').length,
                    globalOverflow: unclippedOverflow.length > 0,
                    overflowPixels: unclippedOverflow.length ? Math.round(windowOverflow) : 0,
                    unlabeled,
                    unnamed,
                    duplicates,
                    remote,
                    tables,
                    sensitiveMarkers,
                    unclippedOverflow,
                    smallTargets,
                    focusableCount: interactive.length,
                };
            }"""
        )
        payload.update({"label": label, "browser": browser_name, "viewport": viewport, "zoom": zoom})
        self.results["page_audits"].append(payload)
        self.audit_contrast(page, label, browser_name, viewport)
        path = payload["path"]
        if not payload["lang"].lower().startswith("fr"):
            self.issue("majeur", "accessibilité", f"lang={payload['lang']}", path)
        if len(payload["h1"]) != 1:
            self.issue("majeur", "hiérarchie", f"h1 visibles={len(payload['h1'])}", path)
        if payload["main"] != 1:
            self.issue("majeur", "landmarks", f"main={payload['main']}", path)
        if payload["globalOverflow"]:
            self.issue("majeur", "responsive", f"débordement global={payload['overflowPixels']}px ({viewport}); éléments={payload['unclippedOverflow']}", path)
        if payload["unlabeled"]:
            self.issue("majeur", "formulaires", f"contrôles sans label={payload['unlabeled']}", path)
        if payload["unnamed"]:
            self.issue("majeur", "accessibilité", f"interactions sans nom={payload['unnamed']}", path)
        if payload["duplicates"]:
            self.issue("majeur", "HTML", f"identifiants dupliqués={payload['duplicates']}", path)
        if payload["remote"]:
            self.issue("majeur", "ressources", f"ressources distantes={payload['remote']}", path)
        if payload["sensitiveMarkers"]:
            self.issue("bloquant", "sécurité", f"marqueurs sensibles={payload['sensitiveMarkers']}", path)
        for index, table in enumerate(payload["tables"]):
            if table["headers"] == 0:
                self.issue("majeur", "tableaux", f"table {index + 1} sans en-tête", path)
            if table["overflow"] and not table["scrollable"]:
                self.issue("majeur", "tableaux", f"table {index + 1} déborde sans conteneur", path)
        return payload

    def auth_checks(self, browser: Browser, browser_name: str) -> None:
        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        self.goto(page, "/login")
        self.check("Login B2B sans inscription", "inscription publique est désactivée" in page.locator("body").inner_text().lower() and "register" not in page.locator("body").inner_text().lower())
        self.check("Autocomplete email", page.locator('input[name="email"]').get_attribute("autocomplete") == "username")
        self.check("Autocomplete mot de passe", page.locator('input[name="password"]').get_attribute("autocomplete") == "current-password")
        self.check("Se souvenir de moi", page.locator('input[name="remember"]').count() == 1)
        password = page.locator('input[name="password"]')
        self.check("Mot de passe masqué", password.get_attribute("type") == "password")
        page.locator('input[name="email"]').focus()
        focus_style = page.locator('input[name="email"]').evaluate("el => ({shadow: getComputedStyle(el).boxShadow, outline: getComputedStyle(el).outlineStyle})")
        self.check("Focus visible au login", focus_style["shadow"] != "none" or focus_style["outline"] != "none", "style de focus calculé")
        page.get_by_role("button", name="Afficher le mot de passe").click()
        self.check("Afficher le mot de passe", password.get_attribute("type") == "text")
        page.get_by_role("button", name="Masquer le mot de passe").click()
        self.check("Remasquer le mot de passe", password.get_attribute("type") == "password")
        self.audit_page(page, "Connexion", browser_name, "desktop")
        self.screenshot(page, "01-login-desktop.png")
        self.goto(page, "/forgot-password")
        forgot_body = page.locator("body").inner_text().lower()
        self.check("Mot de passe oublié", "mot de passe" in forgot_body and page.locator('input[name="email"]').count() == 1)
        self.goto(page, "/reset-password/jeton-qa-invalide?email=qa%40invalid.test")
        self.check("Écran de réinitialisation", page.locator('input[name="password"]').count() == 1 and page.locator('input[name="password_confirmation"]').count() == 1)
        context.close()

        mobile = self.new_context(browser, VIEWPORTS["mobile"])
        page = mobile.new_page()
        self.goto(page, "/login")
        self.audit_page(page, "Connexion", browser_name, "mobile")
        self.screenshot(page, "01-login-mobile.png")
        mobile.close()

        keyboard_context = self.new_context(browser, VIEWPORTS["desktop"])
        page = keyboard_context.new_page()
        self.login(page, "tenant-owner", keyboard=True)
        self.check("Login clavier complet", page.url.endswith("/dashboard"))
        self.goto(page, "/confirm-password")
        self.check("Confirmation du mot de passe", "confirmer" in page.locator("h1").inner_text().lower())
        verification = page.goto(f"{self.base_url}/verify-email", wait_until="networkidle")
        self.check("Vérification e-mail conservée", verification is not None and verification.status == 200, f"arrivée={page.url.removeprefix(self.base_url)}")
        keyboard_context.close()

        expired = self.new_context(browser, VIEWPORTS["desktop"])
        page = expired.new_page()
        self.goto(page, "/login")
        form = page.locator('form[action$="/login"]')
        form.locator('input[name="_token"]').evaluate("el => el.remove()")
        with page.expect_navigation(wait_until="networkidle") as navigation:
            form.evaluate("form => form.submit()")
        response = navigation.value
        body = page.locator("body").inner_text()
        self.check("Page 419 sûre", response.status == 419 and "SQLSTATE" not in body and "Stack trace" not in body, f"statut={response.status}")
        self.screenshot(page, "17-erreur-419.png")
        expired.close()

        errors: list[str] = []
        for email in (ROLE_ACCOUNTS["tenant-owner"], "compte-inexistant@qa.invalid"):
            error_context = self.new_context(browser, VIEWPORTS["desktop"])
            page = error_context.new_page()
            self.goto(page, "/login")
            page.locator('input[name="email"]').fill(email)
            page.locator('input[name="password"]').fill("valeur-invalide-qa")
            with page.expect_navigation(wait_until="networkidle"):
                page.get_by_role("button", name="Se connecter").click()
            errors.append(" ".join(page.locator('[role="alert"]').all_inner_texts()))
            error_context.close()
        self.check("Erreur login sans énumération", len(errors) == 2 and errors[0] == errors[1] and bool(errors[0]), "messages identiques")

    def role_matrix(self, browser: Browser, browser_name: str) -> dict[str, list[str]]:
        matrix: dict[str, list[str]] = {}
        atlas_paths: list[str] = []
        rabat_paths: list[str] = []
        for role in ROLE_ACCOUNTS:
            context = self.new_context(browser, VIEWPORTS["desktop"])
            page = context.new_page()
            self.login(page, role)
            body = page.locator("body").inner_text()
            self.check(f"Rôle affiché {role}", ROLE_LABELS[role] in body)
            desktop_keys = page.locator('[data-nav-surface="desktop"]').evaluate_all("els => els.map(el => el.dataset.navKey)")
            mobile_keys = page.locator('[data-nav-surface="mobile"]').evaluate_all("els => els.map(el => el.dataset.navKey)")
            matrix[role] = sorted(desktop_keys)
            self.check(f"Parité navigation {role}", sorted(desktop_keys) == sorted(mobile_keys), f"entrées={len(desktop_keys)}")
            self.check(f"Destinations interdites absentes {role}", FORBIDDEN_NAVIGATION[role].isdisjoint(desktop_keys), f"interdites={sorted(FORBIDDEN_NAVIGATION[role])}")
            self.check(f"Route active {role}", page.locator('[aria-current="page"]').count() >= 1)
            expected_scope = "Plateforme RentFleet" if role == "platform-admin" else "Atlas Location Démo"
            self.check(f"Identité organisation {role}", expected_scope in body, expected_scope)
            if role == "agency-manager":
                self.check("Identité agence Agency Manager", "Casablanca Centre" in body)
            self.goto(page, "/profile")
            self.check(f"Profil {role}", page.locator("h1").count() == 1 and "profil" in page.locator("h1").inner_text().lower())
            forbidden = "/dashboard" if role == "platform-admin" else "/platform/dashboard"
            response = page.goto(f"{self.base_url}{forbidden}", wait_until="networkidle")
            self.check(f"Accès direct interdit {role}", response is not None and response.status == 403, f"statut={response.status if response else 0}")
            if role == "viewer":
                response = page.goto(f"{self.base_url}/reservations/create", wait_until="networkidle")
                self.check("Viewer création refusée", response is not None and response.status == 403, f"statut={response.status if response else 0}")
            if role == "agency-manager":
                self.goto(page, "/availability")
                options = page.locator('#availability-agency option').all_inner_texts()
                self.check("Agency Manager limité à Casablanca", any("Casablanca" in item for item in options) and not any("Rabat" in item for item in options), str(options))
                self.check("Ressources Rabat identifiées pour le contrôle direct", bool(rabat_paths), f"ressources={len(rabat_paths)}")
                for path in sorted(set(rabat_paths)):
                    response = page.goto(f"{self.base_url}{path}", wait_until="networkidle")
                    self.check(f"Isolation cross-agence {path}", response is not None and response.status in (403, 404), f"statut={response.status if response else 0}")
            if role == "tenant-owner":
                for route, fragment in (("/vehicles", "/vehicles/"), ("/customers", "/customers/"), ("/reservations", "/reservations/")):
                    self.goto(page, route)
                    hrefs = page.locator(f'a[href*="{fragment}"]').evaluate_all("els => els.map(el => new URL(el.href).pathname)")
                    hrefs = [href for href in hrefs if not any(part in href for part in ("/create", "/edit", "/export"))]
                    if hrefs:
                        atlas_paths.append(hrefs[0])
                    rabat_row = page.locator("tr").filter(has_text="Rabat").locator(f'a[href*="{fragment}"]').first
                    if rabat_row.count():
                        rabat_paths.append(rabat_row.get_attribute("href").removeprefix(self.base_url))
            self.goto(page, "/profile")
            page.locator('header button[aria-haspopup="menu"]').click()
            with page.expect_navigation(wait_until="networkidle"):
                page.get_by_role("menuitem", name="Déconnexion").click()
            self.check(f"Déconnexion {role}", page.url.endswith("/login"))
            context.close()
        self.results["role_navigation"] = matrix

        rif = self.new_context(browser, VIEWPORTS["desktop"])
        page = rif.new_page()
        self.goto(page, "/login")
        page.locator('input[name="email"]').fill("owner@rif-demo.test")
        page.locator('input[name="password"]').fill(self.password)
        page.get_by_role("button", name="Se connecter").click()
        page.wait_for_load_state("networkidle")
        for path in sorted(set(atlas_paths)):
            response = page.goto(f"{self.base_url}{path}", wait_until="networkidle")
            self.check(f"Isolation cross-tenant {path}", response is not None and response.status in (403, 404), f"statut={response.status if response else 0}")
        rif.close()
        return matrix

    def mobile_keyboard(self, browser: Browser, browser_name: str) -> None:
        context = self.new_context(browser, VIEWPORTS["mobile"])
        page = context.new_page()
        self.login(page, "tenant-owner")
        trigger = page.get_by_role("button", name="Ouvrir le menu principal")
        trigger.focus()
        page.keyboard.press("Enter")
        dialog = page.get_by_role("dialog", name="Menu principal")
        dialog.wait_for(state="visible")
        self.check("Menu mobile ouvert au clavier", dialog.is_visible())
        self.screenshot(page, "13-navigation-mobile-ouverte.png")
        first_focus = page.evaluate("document.activeElement && (document.activeElement.getAttribute('aria-label') || document.activeElement.innerText || document.activeElement.tagName)")
        self.check("Focus envoyé dans le menu mobile", bool(first_focus) and "Ouvrir" not in first_focus, str(first_focus))
        page.keyboard.press("Escape")
        dialog.wait_for(state="hidden")
        self.check("Menu mobile fermé par Échap", not dialog.is_visible())
        self.check("Focus restitué au déclencheur", trigger.evaluate("el => el === document.activeElement"))
        menu = page.locator('header button[aria-haspopup="menu"]')
        menu.focus()
        page.keyboard.press("Enter")
        page.get_by_role("menu").wait_for(state="visible")
        self.check("Menu utilisateur au clavier", page.get_by_role("menu").is_visible())
        page.keyboard.press("Escape")
        page.get_by_role("menu").wait_for(state="hidden")
        self.check("Menu utilisateur fermé par Échap", not page.get_by_role("menu").is_visible())
        self.check("Focus restitué au menu utilisateur", menu.evaluate("el => el === document.activeElement"))
        trigger.focus()
        page.keyboard.press("Enter")
        reservations_link = page.get_by_role("dialog", name="Menu principal").get_by_role("link", name="Réservations", exact=True)
        reservations_link.focus()
        with page.expect_navigation(wait_until="load"):
            page.keyboard.press("Enter")
        self.check("Navigation principale au clavier", page.url.endswith("/reservations"))
        search = page.locator('#reservation-q')
        search.focus()
        page.keyboard.type("filtre-clavier-e2")
        with page.expect_navigation(wait_until="networkidle"):
            page.keyboard.press("Enter")
        self.check("Filtres au clavier", "q=filtre-clavier-e2" in page.url)
        self.goto(page, "/contracts")
        contract_link = page.locator('a[href*="/contracts/"]').first
        contract_link.focus()
        with page.expect_navigation(wait_until="networkidle"):
            page.keyboard.press("Enter")
        self.check("Consultation contrat au clavier", bool(re.search(r"/contracts/\d+$", page.url)) and page.locator("h1").count() == 1)
        menu = page.locator('header button[aria-haspopup="menu"]')
        menu.focus()
        page.keyboard.press("Enter")
        logout = page.get_by_role("menuitem", name="Déconnexion")
        logout.focus()
        with page.expect_navigation(wait_until="networkidle"):
            page.keyboard.press("Enter")
        self.check("Déconnexion au clavier", page.url.endswith("/login"))
        context.close()

    def reservation_flow(self, browser: Browser, browser_name: str) -> None:
        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        self.login(page, "tenant-owner")
        self.goto(page, "/availability")
        if not self.qa_agency_id or not self.qa_customer_id or not self.qa_driver_id:
            raise RuntimeError("Le triplet QA de réservation n’est pas disponible.")
        page.locator('#availability-agency').select_option(self.qa_agency_id)
        start = (datetime.now() + timedelta(days=120)).replace(hour=10, minute=0, second=0, microsecond=0)
        end = start + timedelta(days=2)
        page.locator('#availability-start').fill(start.strftime("%Y-%m-%dT%H:%M"))
        page.locator('#availability-end').fill(end.strftime("%Y-%m-%dT%H:%M"))
        page.get_by_role("button", name="Rechercher").click()
        page.wait_for_load_state("networkidle")
        create_link = page.get_by_role("link", name="Créer une réservation").first
        self.check("Disponibilité exploitable", create_link.count() == 1)
        create_link.click()
        page.wait_for_load_state("networkidle")

        form = page.locator('form[method="POST"]').last
        form.evaluate("form => form.noValidate = true")
        invalid_submit = page.get_by_role("button", name="Enregistrer")
        invalid_submit.focus()
        with page.expect_navigation(wait_until="networkidle"):
            page.keyboard.press("Enter")
        self.check("Validation réservation visible", "erreur" in page.locator("body").inner_text().lower() or page.locator('.text-red-800').count() > 0)
        invalid_fields = page.locator('[aria-invalid="true"]')
        self.check("Erreur associée au champ", invalid_fields.count() >= 1 and invalid_fields.first.get_attribute("aria-describedby") is not None)
        self.check("Focus sur la première erreur", invalid_fields.first.evaluate("el => el === document.activeElement"))
        self.screenshot(page, "14-reservation-validation.png")

        customer = page.locator('select[name="customer_id"]')
        customer.select_option(self.qa_customer_id)
        page.locator('select[name="driver_id"]').select_option(self.qa_driver_id)
        valid_submit = page.get_by_role("button", name="Enregistrer")
        valid_submit.focus()
        with page.expect_navigation(wait_until="networkidle"):
            page.keyboard.press("Enter")
        created = re.search(r"/reservations/\d+$", page.url)
        self.check("Création réservation QA", bool(created), page.url.removeprefix(self.base_url))
        if not created:
            context.close()
            return
        number = page.locator("h1").inner_text().strip()
        self.audit_page(page, "Réservation QA créée", browser_name, "desktop")
        page.on("dialog", lambda dialog: dialog.accept())
        confirm = page.get_by_role("button", name="Confirmer et bloquer")
        self.check("Action confirmation visible", confirm.count() == 1)
        if confirm.count():
            confirm.focus()
            with page.expect_navigation(wait_until="networkidle"):
                page.keyboard.press("Enter")
        reason = page.locator('textarea[name="reason"]')
        confirmation_visible = confirm.count() == 0 and reason.count() == 1
        self.check("Réservation confirmée", confirmation_visible, f"confirmation_absente={confirm.count() == 0}; annulation_disponible={reason.count() == 1}")
        if reason.count():
            reason.fill("Annulation QA E2 après validation du parcours navigateur")
            cancel = page.get_by_role("button", name="Annuler la réservation")
            cancel.focus()
            with page.expect_navigation(wait_until="networkidle"):
                page.keyboard.press("Enter")
            self.check("Réservation annulée", "Annulée" in page.locator("body").inner_text())
        self.goto(page, "/reservations")
        page.locator('#reservation-q').fill(number)
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Filtrer").click()
        self.check("Recherche réservation", number in page.locator("body").inner_text())
        context.close()

    def continuous_rental_cycle(self, browser: Browser, browser_name: str) -> None:
        if not self.qa_agency_id or not self.qa_customer_id or not self.qa_driver_id:
            raise RuntimeError("Le triplet QA du cycle continu n’est pas disponible.")

        fixture = self.output.parent / "qa-private-fixture.pdf"
        fixture.write_bytes(b"%PDF-1.4\n% RentFleet QA fictive\n%%EOF")

        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        page.on("dialog", lambda dialog: dialog.accept())
        self.login(page, "rental-agent")

        self.goto(page, f"/customers/{self.qa_customer_id}")
        customer_document = page.locator(
            f'form[action$="/customers/{self.qa_customer_id}/documents"]'
        )
        customer_document.locator('input[name="title"]').fill("Identité fictive recette 06G")
        customer_document.locator('input[name="file"]').set_input_files(fixture)
        with page.expect_navigation(wait_until="networkidle"):
            customer_document.get_by_role("button", name="Ajouter le document").click()
        self.check("Cycle — document client privé", "Identité fictive recette 06G" in page.locator("body").inner_text())

        self.goto(page, f"/drivers/{self.qa_driver_id}")
        driver_document = page.locator(
            f'form[action$="/drivers/{self.qa_driver_id}/documents"]'
        )
        driver_document.locator('input[name="title"]').fill("Permis fictif recette 06G")
        driver_document.locator('input[name="file"]').set_input_files(fixture)
        with page.expect_navigation(wait_until="networkidle"):
            driver_document.get_by_role("button", name="Ajouter le permis privé").click()
        self.check("Cycle — document conducteur privé", "Permis fictif recette 06G" in page.locator("body").inner_text())

        self.goto(page, "/availability")
        page.locator("#availability-agency").select_option(self.qa_agency_id)
        start = (datetime.now() + timedelta(days=130)).replace(hour=10, minute=0, second=0, microsecond=0)
        end = start + timedelta(days=2)
        page.locator("#availability-start").fill(start.strftime("%Y-%m-%dT%H:%M"))
        page.locator("#availability-end").fill(end.strftime("%Y-%m-%dT%H:%M"))
        page.get_by_role("button", name="Rechercher").click()
        page.wait_for_load_state("networkidle")
        page.get_by_role("link", name="Créer une réservation").first.click()
        page.wait_for_load_state("networkidle")
        page.locator('select[name="customer_id"]').select_option(self.qa_customer_id)
        page.locator('select[name="driver_id"]').select_option(self.qa_driver_id)
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Enregistrer").click()
        self.check("Cycle — réservation créée", bool(re.search(r"/reservations/\d+$", page.url)))

        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Confirmer et bloquer").click()
        self.check("Cycle — réservation confirmée", "Confirmée" in page.locator("body").inner_text())
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Créer le contrat depuis cette réservation").click()
        contract_match = re.search(r"(/contracts/\d+)$", page.url)
        if not contract_match:
            raise RuntimeError("Le contrat du cycle continu n’a pas été créé.")
        contract_path = contract_match.group(1)

        page.locator("#contract-version-file").set_input_files(fixture)
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Associer le fichier").click()
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Marquer prêt").click()
        page.locator('input[name="accepted_by_name"]').fill("Signataire fictif recette 06G")
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Enregistrer l’acceptation").click()
        self.check("Cycle — contrat accepté", "Accepté" in page.locator("body").inner_text())

        departure_mileage = 500000 if browser_name == "chrome" else 600000
        page.locator("#departure-mileage").fill(str(departure_mileage))
        page.locator("#departure-fuel").fill("75")
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Terminer l’inspection").click()
        self.check("Cycle — inspection de départ", page.get_by_role("button", name="Activer le contrat et remettre le véhicule").count() == 1)
        self.check("Cycle — séparation financière agent", page.get_by_role("button", name="Recevoir").count() == 0)
        context.close()

        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        page.on("dialog", lambda dialog: dialog.accept())
        self.login(page, "accountant")
        self.goto(page, contract_path)
        receive = page.locator(f'form[action$="{contract_path}/deposit/receive"]')
        self.check("Cycle — caution réservée au comptable", receive.count() == 1)
        with page.expect_navigation(wait_until="networkidle"):
            receive.get_by_role("button", name="Recevoir").click()
        context.close()

        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        page.on("dialog", lambda dialog: dialog.accept())
        self.login(page, "rental-agent")
        self.goto(page, contract_path)
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Activer le contrat et remettre le véhicule").click()
        page.locator("#return-mileage").fill(str(departure_mileage + 100))
        page.locator("#return-fuel").fill("75")
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Terminer le retour").click()
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Finaliser le retour").click()
        self.check("Cycle — retour finalisé", "Retourné" in page.locator("body").inner_text())
        context.close()

        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        page.on("dialog", lambda dialog: dialog.accept())
        self.login(page, "accountant")
        self.goto(page, contract_path)
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Créer la facture").click()
        invoice_match = re.search(r"(/finance/invoices/\d+)$", page.url)
        if not invoice_match:
            raise RuntimeError("La facture du cycle continu n’a pas été créée.")
        invoice_path = invoice_match.group(1)

        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Émettre").click()
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Enregistrer").click()
        latest_payment = page.locator("article").first
        with page.expect_navigation(wait_until="networkidle"):
            latest_payment.get_by_role("button", name="Allouer").click()
        with page.expect_navigation(wait_until="networkidle"):
            latest_payment.get_by_role("button", name="Comptabiliser").click()
        self.check("Cycle — facture payée", "Payée" in page.locator("body").inner_text())

        self.goto(page, contract_path)
        refund = page.locator(f'form[action$="{contract_path}/deposit/refund"]')
        refund.locator('input[name="reason"]').fill("Remboursement fictif recette 06G")
        with page.expect_navigation(wait_until="networkidle"):
            refund.get_by_role("button", name="Rembourser").click()
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Vérifier et clôturer").click()
        body = page.locator("body").inner_text()
        self.check("Cycle continu clôturé", "Clôturé" in body and "Solde caution" in body)
        self.audit_page(page, "Contrat clôturé recette 06G", browser_name, "desktop")
        self.screenshot(page, "30-cycle-continu-cloture.png")
        self.results.setdefault("continuous_cycles", {})[browser_name] = {
            "status": "passed",
            "contract_path": contract_path,
            "invoice_path": invoice_path,
            "roles": ["rental-agent", "accountant"],
        }
        context.close()

    def core_routes(self, page: Page) -> list[tuple[str, str]]:
        routes: list[tuple[str, str]] = [("02-dashboard", "/dashboard"), ("03-reservations", "/reservations")]
        dynamic = [
            ("04-reservation", "/reservations", "/reservations/"),
            ("05-contract", "/contracts", "/contracts/"),
            ("06-vehicle", "/vehicles", "/vehicles/"),
            ("07-customer", "/customers", "/customers/"),
        ]
        for name, listing, fragment in dynamic:
            self.goto(page, listing)
            hrefs = page.locator(f'a[href*="{fragment}"]').evaluate_all("els => els.map(el => new URL(el.href).pathname)")
            hrefs = [href for href in hrefs if not any(part in href for part in ("/create", "/edit", "/export"))]
            if hrefs:
                routes.append((name, hrefs[0]))
            else:
                self.issue("majeur", "captures", f"Aucune fiche trouvée depuis {listing}", listing)
        routes.extend([
            ("08-finance", "/finance"),
            ("09-maintenance", "/maintenance"),
            ("10-insurance", "/insurance"),
            ("11-report", "/reports"),
            ("12-administration", "/users"),
        ])
        return routes

    def captures_and_responsive(self, browser: Browser, browser_name: str) -> None:
        route_cache: list[tuple[str, str]] | None = None
        for viewport_name in ("desktop", "mobile"):
            context = self.new_context(browser, VIEWPORTS[viewport_name])
            page = context.new_page()
            self.login(page, "tenant-owner")
            if route_cache is None:
                route_cache = self.core_routes(page)
            for name, route in route_cache:
                self.goto(page, route)
                self.audit_page(page, name, browser_name, viewport_name)
                self.screenshot(page, f"{name}-{viewport_name}.png")
            context.close()

        assert route_cache is not None
        critical = [route_cache[0], route_cache[1], next(item for item in route_cache if item[0] == "05-contract"), next(item for item in route_cache if item[0] == "08-finance")]
        for viewport_name in ("tablet", "desktop-narrow", "mobile-320", "zoom-200-desktop"):
            context = self.new_context(browser, VIEWPORTS[viewport_name])
            page = context.new_page()
            self.login(page, "tenant-owner")
            for name, route in critical:
                self.goto(page, route)
                zoom = "200% équivalent reflow" if viewport_name == "zoom-200-desktop" else "100%"
                self.audit_page(page, name, browser_name, viewport_name, zoom)
            context.close()

    def contracts_and_errors(self, browser: Browser, browser_name: str) -> None:
        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        self.login(page, "tenant-owner")
        self.goto(page, "/contracts")
        paths = page.locator('a[href*="/contracts/"]').evaluate_all("els => [...new Set(els.map(el => new URL(el.href).pathname).filter(path => !path.includes('/print')))]")
        statuses: set[str] = set()
        for path in paths[:10]:
            self.goto(page, path)
            status_candidates = page.locator("[class*='rounded-full']").all_inner_texts()
            statuses.update(item.strip() for item in status_candidates if item.strip())
            self.check(f"Contrat h1 unique {path}", page.locator("h1").count() == 1)
            self.check(f"Contrat sans détail technique {path}", not any(marker in page.locator("body").inner_text().lower() for marker in ("sha256", "pricing_snapshot", "content_hash")))
        self.results["contract_statuses_observed"] = sorted(statuses)
        self.check("Scénarios contrats multiples", len(paths) >= 6, f"fiches={len(paths)}")

        self.goto(page, "/page-e2-inexistante", expected=(404,))
        body = page.locator("body").inner_text()
        self.check("Page 404 sûre", "Page introuvable" in body and "SQLSTATE" not in body and "Stack trace" not in body)
        self.screenshot(page, "15-erreur-404.png")
        context.close()

        viewer = self.new_context(browser, VIEWPORTS["desktop"])
        page = viewer.new_page()
        self.login(page, "viewer")
        response = page.goto(f"{self.base_url}/reservations/create", wait_until="networkidle")
        body = page.locator("body").inner_text()
        self.check("Page 403 sûre", response is not None and response.status == 403 and "SQLSTATE" not in body and "Stack trace" not in body)
        self.screenshot(page, "16-erreur-403.png")
        viewer.close()

    def edge_smoke(self, playwright: Playwright) -> None:
        if not EDGE.exists():
            self.results["limits"].append("Microsoft Edge indisponible")
            return
        browser = playwright.chromium.launch(executable_path=str(EDGE), headless=True)
        self.results["browsers"]["edge"] = browser.version
        try:
            for viewport_name in ("desktop", "mobile"):
                context = self.new_context(browser, VIEWPORTS[viewport_name])
                page = context.new_page()
                self.goto(page, "/login")
                self.audit_page(page, "Connexion", "edge", viewport_name)
                self.login(page, "tenant-owner")
                for route in ("/dashboard", "/reservations", "/contracts", "/finance"):
                    self.goto(page, route)
                    self.audit_page(page, route, "edge", viewport_name)
                context.close()
            self.check("Smoke Edge", True, f"Edge {browser.version}, desktop et mobile")
        finally:
            browser.close()

    def mandatory_password_change(self, browser: Browser) -> None:
        env = os.environ.copy()
        env["APP_ENV"] = "testing"
        env["E2_QA_PASSWORD"] = self.password
        code = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            f"if($db!=='{QA_DATABASE}'){{throw new RuntimeException('QA database guard failed.');}}"
            "$count=App\\Models\\User::withoutGlobalScopes()->where('email','agency-manager@atlas-demo.test')"
            "->update(['must_change_password'=>true]);"
            "if($count!==1){throw new RuntimeException('QA account guard failed.');}"
        )
        self.artisan("tinker", "--env=testing", f"--execute={code}", env=env)
        context = self.new_context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        self.goto(page, "/login")
        page.locator('input[name="email"]').fill(ROLE_ACCOUNTS["agency-manager"])
        page.locator('input[name="password"]').fill(self.password)
        page.get_by_role("button", name="Se connecter").click()
        page.wait_for_load_state("networkidle")
        self.check("Changement initial obligatoire", page.url.endswith("/password/change-required") and "mot de passe" in page.locator("body").inner_text().lower())
        context.close()

    def run(self, port: int) -> int:
        self.screenshots.mkdir(parents=True, exist_ok=True)
        self.output.parent.mkdir(parents=True, exist_ok=True)
        env = os.environ.copy()
        env["APP_ENV"] = "testing"
        env["DB_CONNECTION"] = "pgsql"
        env["DB_DATABASE"] = QA_DATABASE
        env["E2_QA_PASSWORD"] = self.password
        env["SESSION_DRIVER"] = "database"
        env["CACHE_STORE"] = "database"
        env["APP_LOCALE"] = "fr"
        env["APP_FALLBACK_LOCALE"] = "en"
        env["APP_TIMEZONE"] = "Africa/Casablanca"
        env.pop("PHP_CLI_SERVER_WORKERS", None)
        try:
            with sync_playwright() as playwright:
                requested = {
                    name.strip()
                    for name in os.environ.get("RENTFLEET_QA_BROWSERS", "chrome,edge").split(",")
                    if name.strip()
                }
                available = [("chrome", CHROME), ("edge", EDGE)]
                for browser_name, executable in available:
                    if browser_name not in requested:
                        continue
                    if not executable.exists():
                        raise RuntimeError(f"{browser_name} n’est pas disponible.")

                    self.active_browser = browser_name
                    self.prepare_qa(env)
                    if self.server is None:
                        self.start_server(env, port)

                    browser = playwright.chromium.launch(executable_path=str(executable), headless=True)
                    self.results["browsers"][browser_name] = browser.version
                    try:
                        self.auth_checks(browser, browser_name)
                        self.role_matrix(browser, browser_name)
                        self.mobile_keyboard(browser, browser_name)
                        self.reservation_flow(browser, browser_name)
                        self.continuous_rental_cycle(browser, browser_name)
                        self.captures_and_responsive(browser, browser_name)
                        self.contracts_and_errors(browser, browser_name)
                        self.mandatory_password_change(browser)
                    finally:
                        browser.close()
            self.results["limits"].extend([
                "Firefox n’est pas installé sur cette machine.",
                "Aucun lecteur d’écran pilotable n’est disponible.",
                "Lighthouse et axe ne sont pas installés ; l’audit automatique utilise le DOM et les navigateurs réels.",
                "Le zoom 200 % est validé par un viewport CSS divisé par deux, équivalent de reflow automatisé, sans chrome de navigateur visible.",
            ])
        except Exception as exception:  # noqa: BLE001 - safe, redacted failure report
            self.issue("bloquant", "exécution navigateur", f"{type(exception).__name__}: {exception}")
        finally:
            self.stop_server()
            try:
                self.artisan("optimize:clear", "--env=testing", env=env)
            except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                self.issue("majeur", "nettoyage QA", "Le cache temporaire de configuration n’a pas pu être supprimé.")
            self.password = ""
            self.output.write_text(json.dumps(self.results, ensure_ascii=False, indent=2), encoding="utf-8")
        blocking = [item for item in self.results["issues"] if item["severity"] in ("bloquant", "majeur")]
        print(f"browser_checks={len(self.results['checks'])}")
        print(f"page_audits={len(self.results['page_audits'])}")
        print(f"screenshots={len(self.results['screenshots'])}")
        print(f"issues_blocking_or_major={len(blocking)}")
        return 1 if blocking else 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=8042)
    parser.add_argument("--php", type=Path, default=Path(r"C:\Users\pc\.config\herd\bin\php85\php.exe"))
    parser.add_argument("--output", type=Path, default=Path("docs/evidence/browser-data/lot06f-e2-browser-results.json"))
    parser.add_argument("--screenshots", type=Path, default=Path("docs/evidence/screenshots/lot06f-e2"))
    arguments = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    base_url = f"http://127.0.0.1:{arguments.port}"
    audit = BrowserAudit(root, base_url, root / arguments.output, root / arguments.screenshots, arguments.php)
    return audit.run(arguments.port)


if __name__ == "__main__":
    sys.exit(main())
