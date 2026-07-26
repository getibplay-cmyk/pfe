"""Real-browser validation for RentFleet Lot 06F-G2.

The campaign is intentionally self-contained. It uses the installed Playwright
package and system browsers, generates an in-memory password, and mutates only
the guarded ``rentfleet_test`` database.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path
from typing import Any

from playwright.sync_api import Browser, Page, sync_playwright


ACCOUNTS = {
    "platform-admin": "platform@rentfleet.test",
    "tenant-owner": "tenant-owner@atlas-demo.test",
    "agency-manager": "agency-manager@atlas-demo.test",
    "rental-agent": "rental-agent@atlas-demo.test",
    "fleet-manager": "fleet-manager@atlas-demo.test",
    "accountant": "accountant@atlas-demo.test",
    "viewer-auditor": "viewer-auditor@atlas-demo.test",
}

VIEWPORTS = {
    "desktop": {"width": 1440, "height": 900},
    "desktop-compact": {"width": 1024, "height": 768},
    "mobile": {"width": 390, "height": 844},
    "mobile-small": {"width": 320, "height": 568},
}

CHROME = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
EDGE = Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe")


class Campaign:
    def __init__(self, root: Path, php: Path, base_url: str, output: Path, screenshots: Path) -> None:
        self.root = root
        self.php = php
        self.base_url = base_url.rstrip("/")
        self.output = output
        self.screenshots = screenshots
        self.password = secrets.token_urlsafe(32)
        self.server: subprocess.Popen[bytes] | None = None
        self.source_role_id: str | None = None
        self.replacement_role_id: str | None = None
        self.employee_id: str | None = None
        self.results: dict[str, Any] = {
            "lot": "06F-G2",
            "generated_at": datetime.now().astimezone().isoformat(),
            "database": "rentfleet_test",
            "viewports": VIEWPORTS,
            "browsers": {},
            "checks": [],
            "screenshots": [],
            "issues": [],
        }

    def check(self, name: str, passed: bool, details: str = "") -> None:
        self.results["checks"].append({"name": name, "passed": passed, "details": details[:500]})
        if not passed:
            self.results["issues"].append({"name": name, "details": details[:500]})

    def artisan(self, *arguments: str, env: dict[str, str], timeout: int = 120) -> subprocess.CompletedProcess[str]:
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

    def prepare(self, env: dict[str, str]) -> None:
        self.artisan("optimize:clear", "--env=testing", env=env)
        emails = ",".join(f"'{email}'" for email in ACCOUNTS.values())
        account_guard = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            "if($db!=='rentfleet_test'){throw new RuntimeException('Browser database guard failed.');}"
            f"$emails=[{emails}];"
            "$count=App\\Models\\User::withoutGlobalScopes()->whereIn('email',$emails)->count();"
            "echo 'demo_account_count='.$count;"
        )
        guarded = self.artisan("tinker", "--env=testing", f"--execute={account_guard}", env=env)
        account_count = re.search(r"demo_account_count=(\d+)", guarded.stdout)
        if not account_count:
            raise RuntimeError("Le garde des comptes de démonstration n’a pas produit de compteur.")
        if account_count.group(1) == "0":
            self.artisan("db:seed", "--env=testing", "--no-interaction", env=env, timeout=300)
        elif account_count.group(1) != str(len(ACCOUNTS)):
            raise RuntimeError("Le jeu de comptes navigateur est partiel ; aucune correction automatique appliquée.")

        suffix = secrets.token_hex(4)
        setup = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            "if($db!=='rentfleet_test'){throw new RuntimeException('Browser database guard failed.');}"
            "$password=(string)getenv('G2_QA_PASSWORD');"
            "$hash=Hash::make($password);"
            f"$emails=[{emails}];"
            "$count=App\\Models\\User::withoutGlobalScopes()->whereIn('email',$emails)"
            "->update(['password'=>$hash,'must_change_password'=>false,'is_active'=>true]);"
            "if($count!==7){throw new RuntimeException('Browser account guard failed.');}"
            "$tenant=App\\Models\\Tenant::where('slug','atlas-location-demo')->firstOrFail();"
            "$agency=App\\Models\\Agency::withoutGlobalScopes()->where('tenant_id',$tenant->id)->where('is_active',true)->orderBy('id')->firstOrFail();"
            "$owner=App\\Models\\User::withoutGlobalScopes()->where('email','tenant-owner@atlas-demo.test')->firstOrFail();"
            "$permission=App\\Models\\Permission::where('slug','reservation.view')->firstOrFail();"
            f"$suffix='{suffix}';"
            "$ids=app(App\\Support\\Tenancy\\TenantContext::class)->run($tenant,function()use($tenant,$agency,$owner,$permission,$hash,$suffix){"
            "$source=App\\Models\\Role::forceCreate(['tenant_id'=>$tenant->id,'name'=>'QA G2 Accueil '.$suffix,"
            "'slug'=>'qa-g2-source-'.$suffix,'is_system'=>false,'is_active'=>true,'created_by'=>$owner->id]);"
            "$target=App\\Models\\Role::forceCreate(['tenant_id'=>$tenant->id,'name'=>'QA G2 Lecture '.$suffix,"
            "'slug'=>'qa-g2-target-'.$suffix,'is_system'=>false,'is_active'=>true,'created_by'=>$owner->id]);"
            "$source->permissions()->sync([$permission->id]);$target->permissions()->sync([$permission->id]);"
            "foreach([$source,$target] as $role){DB::table('role_agency_delegations')->insert(["
            "'tenant_id'=>$tenant->id,'agency_id'=>$agency->id,'role_id'=>$role->id,'delegated_by'=>$owner->id,"
            "'created_at'=>now(),'updated_at'=>now()]);}"
            "$employee=App\\Models\\User::forceCreate(['tenant_id'=>$tenant->id,'agency_id'=>$agency->id,"
            "'role_id'=>$source->id,'name'=>'Collaborateur QA G2','email'=>'qa-g2-'.$suffix.'@invalid.test',"
            "'email_verified_at'=>now(),'password'=>$hash,'must_change_password'=>false,'is_active'=>true]);"
            "return [$source->id,$target->id,$employee->id];},$agency->id);"
            "foreach($emails as $email){RateLimiter::clear(Str::transliterate(Str::lower($email).'|127.0.0.1'));}"
            "echo 'fixture='.implode(',',$ids);"
        )
        prepared = self.artisan("tinker", "--env=testing", f"--execute={setup}", env=env)
        fixture = re.search(r"fixture=(\d+),(\d+),(\d+)", prepared.stdout)
        if not fixture:
            raise RuntimeError("La préparation QA G2 n’a pas produit les identifiants attendus.")
        self.source_role_id, self.replacement_role_id, self.employee_id = fixture.groups()
        self.check("Garde et comptes navigateur", True, "rentfleet_test ; sept rôles")

        self.artisan("notifications:generate-operational", "--env=testing", env=env)
        history = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            "if($db!=='rentfleet_test'){throw new RuntimeException('Notification guard failed.');}"
            "$tenant=App\\Models\\Tenant::where('slug','atlas-location-demo')->firstOrFail();"
            "$notification=App\\Models\\InternalNotification::withoutGlobalScopes()"
            "->where('tenant_id',$tenant->id)->whereNull('resolved_at')->orderBy('id')->first();"
            "if($notification){$notification->forceFill(['resolved_at'=>now(),'resolution_reason'=>'cause_disparue'])->save();}"
            "echo 'history_fixture=ok';"
        )
        self.artisan("tinker", "--env=testing", f"--execute={history}", env=env)
        self.artisan("config:cache", "--env=testing", env=env)
        guard = self.artisan(
            "tinker",
            "--execute=$db=DB::selectOne('select current_database() as db')->db;"
            "if($db!=='rentfleet_test'){throw new RuntimeException('Cached database guard failed.');}"
            "echo 'cached_guard=ok';",
            env=env,
        )
        self.check("Configuration HTTP de test", "cached_guard=ok" in guard.stdout, "rentfleet_test")

    def start_server(self, env: dict[str, str], port: int) -> None:
        router = self.root / "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
        flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
        self.server = subprocess.Popen(
            [str(self.php), "-S", f"127.0.0.1:{port}", str(router)],
            cwd=self.root / "public",
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            creationflags=flags,
        )
        deadline = time.time() + 30
        while time.time() < deadline:
            if self.server.poll() is not None:
                raise RuntimeError("Le serveur navigateur s’est arrêté prématurément.")
            try:
                with urllib.request.urlopen(f"{self.base_url}/health", timeout=2) as response:
                    if response.status == 200:
                        self.check("Serveur navigateur", True, "GET /health = 200")
                        return
            except (urllib.error.URLError, TimeoutError):
                time.sleep(0.4)
        raise RuntimeError("Le serveur navigateur ne répond pas.")

    def stop_server(self) -> None:
        if self.server and self.server.poll() is None:
            self.server.terminate()
            try:
                self.server.wait(timeout=8)
            except subprocess.TimeoutExpired:
                self.server.kill()

    def context(self, browser: Browser, viewport: dict[str, int]):
        return browser.new_context(
            viewport=viewport,
            locale="fr-FR",
            timezone_id="Africa/Casablanca",
            color_scheme="light",
            reduced_motion="reduce",
        )

    def goto(self, page: Page, path: str, expected: tuple[int, ...] = (200,)) -> int:
        response = page.goto(f"{self.base_url}{path}", wait_until="networkidle")
        status = response.status if response else 0
        self.check(f"HTTP {path}", status in expected, f"statut={status}")
        return status

    def login(self, page: Page, role: str) -> None:
        self.goto(page, "/login")
        page.locator('input[name="email"]').fill(ACCOUNTS[role])
        page.locator('input[name="password"]').fill(self.password)
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Se connecter").click()
        expected = "/platform/dashboard" if role == "platform-admin" else "/dashboard"
        errors = " ".join(page.locator('[role="alert"]').all_inner_texts()).strip()
        details = page.url.removeprefix(self.base_url)
        if errors:
            details += "; erreur_affichée=true"
        self.check(f"Connexion {role}", page.url.endswith(expected), details)

    def audit_page(self, page: Page, name: str) -> None:
        h1 = page.locator("h1").count()
        overflow = page.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1")
        unlabeled = page.evaluate(
            """Array.from(document.querySelectorAll('input:not([type=hidden]),select,textarea')).filter(
                el => !el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')
            ).length"""
        )
        self.check(f"Titre unique {name}", h1 == 1, f"h1={h1}")
        self.check(f"Sans débordement {name}", bool(overflow), f"largeur={page.viewport_size}")
        self.check(f"Champs nommés {name}", unlabeled == 0, f"sans_label={unlabeled}")

    def screenshot(self, page: Page, name: str) -> None:
        target = self.screenshots / name
        page.screenshot(path=str(target), full_page=False)
        self.results["screenshots"].append(str(target.relative_to(self.root)).replace("\\", "/"))

    def role_smoke(self, browser: Browser, role: str) -> None:
        context = self.context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        self.login(page, role)
        if role == "platform-admin":
            self.goto(page, "/platform/tenants")
            self.goto(page, "/roles", (403,))
        else:
            self.goto(page, "/notifications")
            self.goto(page, "/platform/tenants", (403,))
            desktop_keys = page.locator('div[data-component="app-shell"] > aside [data-nav-key]').evaluate_all(
                "els => els.map(el => el.dataset.navKey)"
            )
            mobile_keys = page.locator('#navigation-mobile [data-nav-key]').evaluate_all("els => els.map(el => el.dataset.navKey)")
            self.check(f"Navigation cohérente {role}", desktop_keys == mobile_keys, f"{len(desktop_keys)} entrées")
        self.audit_page(page, f"{role} desktop")
        context.close()

    def owner_workflows(self, browser: Browser, browser_name: str) -> None:
        context = self.context(browser, VIEWPORTS["desktop"])
        page = context.new_page()
        self.login(page, "tenant-owner")

        self.goto(page, "/notifications")
        self.audit_page(page, "notifications actives")
        self.screenshot(page, f"g2-{browser_name}-notifications-active-1440x900.png")
        page.locator("#notification-status").select_option("resolved")
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Filtrer").click()
        self.check("Historique résolu visible", "status=resolved" in page.url, page.url.removeprefix(self.base_url))
        self.screenshot(page, f"g2-{browser_name}-notifications-resolved-1440x900.png")

        self.goto(page, "/roles")
        self.audit_page(page, "matrice des rôles")
        self.screenshot(page, f"g2-{browser_name}-roles-matrix-1440x900.png")
        self.goto(page, f"/roles/{self.source_role_id}/edit")
        self.check("Impact de remplacement visible", page.get_by_text("1 utilisateur(s) seront réaffectés.").count() == 1)
        self.check(
            "Candidat de remplacement filtré",
            page.locator(f'#replacement-role option[value="{self.replacement_role_id}"]').count() == 1,
        )
        self.audit_page(page, "remplacement de rôle")
        page.locator("#replacement-impact").scroll_into_view_if_needed()
        self.screenshot(page, f"g2-{browser_name}-role-replacement-1440x900.png")

        page.locator('input[name="is_active"][type="checkbox"]').uncheck()
        page.locator("#replacement-role").select_option(str(self.replacement_role_id))
        page.locator('input[name="confirm_replacement"]').check()
        page.once("dialog", lambda dialog: dialog.accept())
        with page.expect_navigation(wait_until="networkidle"):
            page.get_by_role("button", name="Enregistrer").click()
        self.check("Remplacement atomique par l’interface", page.url.endswith("/roles"), page.url)
        context.close()

    def responsive(self, browser: Browser, browser_name: str) -> None:
        for viewport_name in ("desktop-compact", "mobile", "mobile-small"):
            context = self.context(browser, VIEWPORTS[viewport_name])
            page = context.new_page()
            self.login(page, "tenant-owner")
            self.goto(page, "/notifications")
            if viewport_name.startswith("mobile"):
                menu_button = page.get_by_role("button", name="Ouvrir le menu principal")
                if viewport_name == "mobile-small":
                    menu_button.focus()
                    page.keyboard.press("Enter")
                else:
                    menu_button.click()
                page.locator("#navigation-mobile").wait_for(state="visible")
                self.check(
                    f"Menu mobile {viewport_name}",
                    page.locator("#navigation-mobile").is_visible(),
                    str(VIEWPORTS[viewport_name]),
                )
                if viewport_name == "mobile-small":
                    mobile_dialog = page.locator('#navigation-mobile [role="dialog"]')
                    self.check(
                        "État synchronisé du menu à 320 px",
                        menu_button.get_attribute("aria-expanded") == "true",
                        "aria-expanded=true",
                    )
                    focus_in_menu = page.evaluate(
                        "document.activeElement?.closest('#navigation-mobile [role=\"dialog\"]') !== null"
                    )
                    page.keyboard.press("Tab")
                    focus_after_tab = page.evaluate(
                        "document.activeElement?.closest('#navigation-mobile [role=\"dialog\"]') !== null"
                    )
                    self.check(
                        "Navigation clavier du menu à 320 px",
                        bool(focus_in_menu and focus_after_tab),
                        "focus initial et suivant contenus dans le dialogue",
                    )
                    page.keyboard.press("Escape")
                    page.locator("#navigation-mobile").wait_for(state="hidden")
                    self.check(
                        "Fermeture clavier du menu à 320 px",
                        menu_button.get_attribute("aria-expanded") == "false"
                        and page.evaluate("document.activeElement === document.querySelector('[aria-controls=\"navigation-mobile\"]')"),
                        "Échap ferme et restitue le focus",
                    )
                    menu_button.click()
                    page.locator("#navigation-mobile").wait_for(state="visible")
                    mobile_dialog.get_by_role("button", name="Fermer le menu").click()
                    page.locator("#navigation-mobile").wait_for(state="hidden")
                    self.check(
                        "Fermeture explicite du menu à 320 px",
                        menu_button.get_attribute("aria-expanded") == "false",
                        "bouton de fermeture fonctionnel",
                    )
                    menu_button.click()
                    page.locator("#navigation-mobile").wait_for(state="visible")
            self.audit_page(page, f"notifications {viewport_name}")
            size = VIEWPORTS[viewport_name]
            self.screenshot(page, f"g2-{browser_name}-notifications-{size['width']}x{size['height']}.png")
            context.close()

    def run_browsers(self) -> None:
        with sync_playwright() as playwright:
            available = [("chrome", CHROME), ("edge", EDGE)]
            for name, executable in available:
                if not executable.exists():
                    self.results["browsers"][name] = {"available": False}
                    continue
                browser = playwright.chromium.launch(executable_path=str(executable), headless=True)
                self.results["browsers"][name] = {"available": True, "version": browser.version}
                try:
                    if name == "chrome":
                        for role in ACCOUNTS:
                            self.role_smoke(browser, role)
                        self.owner_workflows(browser, name)
                        self.responsive(browser, name)
                    else:
                        self.role_smoke(browser, "platform-admin")
                        self.role_smoke(browser, "tenant-owner")
                        self.responsive(browser, name)
                finally:
                    browser.close()

    def verify_replacement(self, env: dict[str, str]) -> None:
        code = (
            "$db=DB::selectOne('select current_database() as db')->db;"
            "if($db!=='rentfleet_test'){throw new RuntimeException('Replacement guard failed.');}"
            f"$source=App\\Models\\Role::withoutGlobalScopes()->findOrFail({self.source_role_id});"
            f"$user=App\\Models\\User::withoutGlobalScopes()->findOrFail({self.employee_id});"
            f"if($source->is_active||$user->role_id!=={self.replacement_role_id})"
            "{throw new RuntimeException('Replacement verification failed.');}"
            "echo 'replacement_verified=ok';"
        )
        verified = self.artisan("tinker", "--execute="+code, env=env)
        self.check("Preuve serveur du remplacement", "replacement_verified=ok" in verified.stdout)

    def write(self) -> None:
        self.results["passed"] = not self.results["issues"]
        self.output.write_text(json.dumps(self.results, ensure_ascii=False, indent=2), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--php", type=Path, required=True)
    parser.add_argument("--base-url", default="http://127.0.0.1:8092")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--screenshots", type=Path, required=True)
    args = parser.parse_args()

    root = args.root.resolve()
    output = args.output if args.output.is_absolute() else root / args.output
    screenshots = args.screenshots if args.screenshots.is_absolute() else root / args.screenshots
    output.parent.mkdir(parents=True, exist_ok=True)
    screenshots.mkdir(parents=True, exist_ok=True)

    env = os.environ.copy()
    env["APP_ENV"] = "testing"
    env["G2_QA_PASSWORD"] = secrets.token_urlsafe(32)
    env["SESSION_DRIVER"] = "database"
    env["CACHE_STORE"] = "database"
    env["APP_LOCALE"] = "fr"
    env["APP_FALLBACK_LOCALE"] = "en"
    env["APP_TIMEZONE"] = "Africa/Casablanca"
    env["DEMO_PASSWORD"] = env["G2_QA_PASSWORD"]
    env.pop("PHP_CLI_SERVER_WORKERS", None)
    port = int(args.base_url.rsplit(":", 1)[1])
    campaign = Campaign(root, args.php, args.base_url, output, screenshots)
    campaign.password = env["G2_QA_PASSWORD"]

    try:
        campaign.prepare(env)
        campaign.start_server(env, port)
        campaign.run_browsers()
        campaign.verify_replacement(env)
    except Exception as exception:
        campaign.check("Exécution de la campagne", False, f"{type(exception).__name__}: {exception}")
    finally:
        campaign.stop_server()
        try:
            campaign.artisan("optimize:clear", "--env=testing", env=env)
        except Exception as exception:
            campaign.check("Nettoyage du cache QA", False, f"{type(exception).__name__}: {exception}")
        campaign.write()

    print(json.dumps({
        "passed": campaign.results["passed"],
        "checks": len(campaign.results["checks"]),
        "issues": len(campaign.results["issues"]),
        "screenshots": len(campaign.results["screenshots"]),
    }, ensure_ascii=False))
    return 0 if campaign.results["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
