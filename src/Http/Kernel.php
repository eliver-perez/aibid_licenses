<?php
declare(strict_types=1);
namespace Aibid\Http;

use Aibid\App;
use Aibid\Application\Actor;
use Aibid\Domain\{Input, Problem};
use BaconQrCode\Renderer\{ImageRenderer, Image\SvgImageBackEnd, RendererStyle\RendererStyle};
use BaconQrCode\Writer;

final class Kernel
{
    private ?array $session = null;
    private ?Actor $actor = null;
    private string $token = '';
    private string $path = '/';

    public function __construct(private App $app) {}

    public function handle(): void
    {
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        try {
            $this->dispatch();
        } catch (Problem $problem) {
            http_response_code($problem->status);
            if ($problem->status === 429) { header('Retry-After: 900'); }
            $this->render('error', ['title' => 'No se pudo completar la operación', 'message' => $problem->getMessage(), 'status' => $problem->status]);
        }
    }

    private function dispatch(): void
    {
        if (str_starts_with($this->path, '/v1/')) {
            (new ApiKernel($this->app))->handle();
            return;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $https = ($_SERVER['HTTPS'] ?? '') === 'on';
        if (!$https && !($this->app->config->development() && in_array($ip, ['127.0.0.1', '::1'], true))) { throw new Problem('El panel requiere una conexión HTTPS.', 403); }
        if (!Network::allows($this->app->config->get('ADMIN_NETWORKS'), $ip)) { throw new Problem('El panel no está disponible desde esta red.', 403); }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); throw new Problem('Método no permitido.', 405); }
        $uploadRoute = $this->path === '/admin/offline/import';
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ($uploadRoute ? 98304 : 65536)) { throw new Problem('El formulario supera el tamaño permitido.', 413); }
        $cookie = $_COOKIE[$this->cookieName()] ?? null;
        $this->token = is_string($cookie) ? $cookie : '';
        $this->session = $this->app->sessions->load($this->token);
        if ($this->session === null) {
            $this->setToken($this->app->sessions->create());
        }
        if ($method === 'POST') {
            $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
            if ($contentType !== ($uploadRoute ? 'multipart/form-data' : 'application/x-www-form-urlencoded')) { throw new Problem('El formato del formulario no es válido.', 415); }
            $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
            if (($origin !== null && $origin !== rtrim($this->app->config->get('APP_URL'), '/')) || !$this->app->sessions->validCsrf($this->token, $_POST['csrf'] ?? null)) { throw new Problem('El formulario venció o no pertenece a esta sesión. Recarga la página.', 403); }
        }
        if ($this->session['stage'] === 'authenticated') {
            $this->actor = new Actor($this->session['admin_uuid'], $this->session['role'], $this->session['display_name']);
        }
        if ($this->path === '/') { $this->redirect($this->actor ? '/admin' : '/login'); return; }
        if ($this->path === '/login') {
            if ($this->actor) { $this->redirect('/admin'); return; }
            if ($method === 'POST') {
                try {
                    $token = $this->app->auth->login(Input::text($_POST, 'login'), $this->password('password'), $ip, $this->token);
                    $this->setToken($token); $this->redirect('/mfa'); return;
                } catch (Problem $problem) {
                    http_response_code($problem->status);
                    if ($problem->status === 429) { header('Retry-After: 900'); }
                    $this->render('login', ['title' => 'Iniciar sesión', 'error' => $problem->getMessage()]); return;
                }
            }
            $this->render('login', ['title' => 'Iniciar sesión']); return;
        }
        if ($this->path === '/mfa') { $this->mfa($method, $ip); return; }
        if ($this->path === '/logout' && $method === 'POST') {
            $this->app->auth->logout($this->token, $this->session['admin_uuid']);
            setcookie($this->cookieName(), '', $this->cookieOptions(time() - 3600));
            $this->redirect('/login'); return;
        }
        if ($this->actor === null) { $this->redirect($this->session['stage'] === 'pending' ? '/mfa' : '/login'); return; }
        $operationId = $method === 'POST' ? Input::text($_POST, 'operation_id') : '';
        if ($this->path === '/admin' && $method === 'GET') { $this->render('dashboard', ['title' => 'Resumen', 'stats' => $this->app->read->dashboard()]); return; }
        if ($this->path === '/admin/offline' && $method === 'GET') { $this->listing('offline','Solicitudes offline'); return; }
        if ($this->path === '/admin/offline/import' && $method === 'POST') {
            $this->actor->requireRole(['superadmin','operator']);
            $this->app->rate->consume('offline-import',$this->actor->id,30,60);
            $file = $_FILES['request_file'] ?? null;
            if (!is_array($file) || !isset($file['error'],$file['tmp_name'],$file['size']) || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) { throw new Problem('Selecciona un archivo .licreq válido de hasta 64 KiB.'); }
            if ($file['size'] > 65536) { throw new Problem('El archivo supera 64 KiB.',413); }
            $envelope = file_get_contents($file['tmp_name'],false,null,0,65537);
            if ($envelope === false) { throw new Problem('No se pudo leer el archivo.'); }
            $result = $this->app->offline->import($this->actor,$operationId,$envelope);
            $this->redirect('/admin/offline/'.$result['id']); return;
        }
        if (preg_match('~^/admin/offline/([a-f0-9-]{36})(?:/(approve|reject|download))?$~',$this->path,$matches)) {
            if ($method === 'POST' && in_array($matches[2] ?? '',['approve','reject'],true)) {
                $this->actor->requireRole(['superadmin','operator']);
                if ($matches[2] === 'approve' && Input::text($_POST,'force_activation_id',36,false) !== '') { $this->actor->requireRole(['superadmin']); $this->confirmIdentity(); }
                $result = $this->app->offline->decide($this->actor,$operationId,$matches[1],$matches[2] === 'approve' ? 'approved' : 'rejected',$_POST);
                $this->redirect('/admin/offline/'.$result['id']); return;
            }
            if ($method === 'GET') {
                $offline = $this->app->read->offline($matches[1]);
                if (($matches[2] ?? '') === 'download') {
                    $this->actor->requireRole(['superadmin','operator']);
                    if ($offline['status'] !== 'approved') { throw new Problem('La solicitud todavía no tiene un archivo autorizado.',409); }
                    $revision = $this->app->read->revision($offline['license_text'],$offline['revision_text']);
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Content-Disposition: attachment; filename="request-'.$matches[1].'.lic"');
                    echo $revision['license_jws']; return;
                }
                if (!isset($matches[2])) {
                    $selected = $offline['payload']['license_id'] ?? Input::text($_GET,'license_id',36,false);
                    $license = $selected === '' ? null : $this->app->read->license($selected);
                    if ($license !== null && $license['product_id'] !== $offline['product_id']) { throw new Problem('La licencia corresponde a otro producto.'); }
                    $query = Input::text($_GET,'q',100,false);
                    $this->render('offline-detail',['title'=>'Revisar solicitud offline','offline'=>$offline,'license'=>$license,'candidates'=>$offline['payload']['action'] === 'activate' && $offline['status'] === 'pending' ? $this->app->read->offlineCandidates($offline['product_id'],$query) : [],'query'=>$query]); return;
                }
            }
        }
        if (preg_match('~^/admin/licenses/([a-f0-9-]{36})/release$~',$this->path,$matches) && $method === 'POST') {
            $this->actor->requireRole(['superadmin']); $this->confirmIdentity();
            $this->licenseResult($this->app->offline->release($this->actor,$operationId,$matches[1],$_POST)); return;
        }
        if ($this->path === '/admin/customers' && $method === 'GET') { $this->listing('customers', 'Clientes'); return; }
        if ($this->path === '/admin/customers/new' && $method === 'GET') { $this->actor->requireRole(['superadmin', 'operator']); $this->render('customer-form', ['title' => 'Nuevo cliente', 'customer' => null]); return; }
        if (preg_match('~^/admin/customers/([a-f0-9-]{36}|create)$~', $this->path, $matches)) {
            $id = $matches[1] === 'create' ? null : $matches[1];
            if ($method === 'POST') {
                $result = $this->app->catalog->saveCustomer($this->actor, $operationId, $id, $_POST);
                $this->redirect('/admin/customers/' . $result['id']); return;
            }
            if ($id !== null) { $this->render('customer-form', ['title' => 'Detalle del cliente', 'customer' => $this->app->read->customer($id)]); return; }
        }
        if ($this->path === '/admin/products' && $method === 'GET') { $this->render('products', ['title' => 'Productos', 'products' => $this->app->read->products()]); return; }
        if ($this->path === '/admin/products/new' && $method === 'GET') { $this->actor->requireRole(['superadmin']); $this->render('product-form', ['title' => 'Nuevo producto', 'product' => null, 'capabilities' => []]); return; }
        if (preg_match('~^/admin/products/([a-z][a-z0-9_]{1,63})(/capabilities)?$~', $this->path, $matches)) {
            $id = $matches[1] === 'create' ? null : $matches[1];
            if ($method === 'POST') {
                $result = isset($matches[2]) ? $this->app->catalog->saveCapability($this->actor, $operationId, (string) $id, $_POST) : $this->app->catalog->saveProduct($this->actor, $operationId, $id, $_POST);
                $this->redirect('/admin/products/' . $result['id']); return;
            }
            if ($id !== null && !isset($matches[2])) { $this->render('product-form', ['title' => 'Detalle del producto', 'product' => $this->app->read->product($id), 'capabilities' => $this->app->read->capabilities($id)]); return; }
        }
        if ($this->path === '/admin/licenses' && $method === 'GET') { $this->listing('licenses', 'Licencias'); return; }
        if ($this->path === '/admin/licenses/new' && $method === 'GET') {
            $this->actor->requireRole(['superadmin', 'operator']);
            $this->render('license-form', ['title' => 'Emitir licencia', 'customers' => $this->app->read->customers(), 'products' => $this->app->read->products(), 'capabilities' => $this->app->read->capabilities()]); return;
        }
        if ($this->path === '/admin/licenses/create' && $method === 'POST') { $this->licenseResult($this->app->licenses->issue($this->actor, $operationId, $_POST)); return; }
        if (preg_match('~^/admin/licenses/([a-f0-9-]{36})/revisions/([a-f0-9-]{36})/download$~', $this->path, $matches) && $method === 'GET') {
            $this->actor->requireRole(['superadmin','operator']);
            $revision = $this->app->read->revision($matches[1], $matches[2]);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="license-' . $matches[1] . '-r' . (int)$revision['license_revision'] . '.lic"');
            echo $revision['license_jws']; return;
        }
        if (preg_match('~^/admin/licenses/([a-f0-9-]{36})(?:/(features|renew|maintenance|reissue|revoke))?$~', $this->path, $matches)) {
            if ($method === 'POST' && isset($matches[2])) {
                if (in_array($matches[2], ['revoke', 'reissue'], true)) { $this->confirmIdentity(); }
                $this->licenseResult($this->app->licenses->change($this->actor, $operationId, $matches[1], $matches[2], $_POST)); return;
            }
            if ($method === 'GET' && !isset($matches[2])) { $this->render('license-detail', ['title' => 'Detalle de licencia', 'license' => $this->app->read->license($matches[1]), 'capabilities' => $this->app->read->capabilities()]); return; }
        }
        if ($this->path === '/admin/audit' && $method === 'GET') { $this->listing('audit', 'Auditoría'); return; }
        if ($this->path === '/admin/users' && $method === 'GET') { $this->actor->requireRole(['superadmin']); $this->render('users', ['title' => 'Administradores', 'users' => $this->app->read->users()]); return; }
        if ($this->path === '/admin/users/create' && $method === 'POST') {
            $this->actor->requireRole(['superadmin']); $this->confirmIdentity();
            $this->app->admins->create($this->actor, $operationId, $_POST); $this->redirect('/admin/users'); return;
        }
        if (preg_match('~^/admin/users/([a-f0-9-]{36})$~', $this->path, $matches) && $method === 'POST') {
            $this->actor->requireRole(['superadmin']); $this->confirmIdentity();
            $this->app->admins->update($this->actor, $operationId, $matches[1], $_POST); $this->redirect('/admin/users'); return;
        }
        if ($this->path === '/admin/security') {
            if ($method === 'POST') {
                $password = $this->password('new_password');
                if ($password !== $this->password('confirmation')) { throw new Problem('Las contraseñas nuevas no coinciden.'); }
                $this->confirmIdentity(); $this->app->auth->changePassword($this->actor, $password);
                $this->redirect('/login'); return;
            }
            $this->render('security', ['title' => 'Seguridad de tu cuenta']); return;
        }
        throw new Problem('La página solicitada no existe.', 404);
    }

    private function mfa(string $method, string $ip): void
    {
        if ($this->actor) { $this->redirect('/admin'); return; }
        if ($this->session['stage'] !== 'pending') { $this->redirect('/login'); return; }
        $error = null;
        if ($method === 'POST') {
            try {
                $result = $this->app->auth->verifyMfa($this->token, Input::text($_POST, 'code', 64), $ip);
                $this->setToken($result['token']);
                $this->actor = new Actor($this->session['admin_uuid'], $this->session['role'], $this->session['display_name']);
                if ($result['recovery_codes'] !== []) { $this->render('recovery', ['title' => 'Guarda tus códigos de recuperación', 'codes' => $result['recovery_codes']]); return; }
                $this->redirect('/admin'); return;
            } catch (Problem $problem) { $error = $problem->getMessage(); http_response_code($problem->status); if ($problem->status === 429) { header('Retry-After: 900'); } }
        }
        $secret = null;
        $qr = null;
        if ($this->session['user_mfa'] === null && $this->session['enrollment_secret'] !== null) {
            $secret = $this->app->crypto->decrypt($this->session['enrollment_secret'], 'mfa:' . $this->session['admin_uuid']);
            $writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd()));
            $qr = 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($this->app->totp->provisioningUri($secret, $this->session['login'])));
        }
        $this->render('mfa', ['title' => $secret === null ? 'Verificar acceso' : 'Protege tu cuenta', 'secret' => $secret, 'qr' => $qr, 'error' => $error]);
    }

    private function listing(string $kind, string $title): void
    {
        $filters = [];
        foreach (['q', 'state', 'product_id', 'status', 'type', 'expiry'] as $key) { $filters[$key] = Input::text($_GET, $key, 100, false); }
        $page = isset($_GET['page']) && is_string($_GET['page']) && ctype_digit($_GET['page']) ? min(1000000, (int) $_GET['page']) : 1;
        $this->render($kind, ['title' => $title, 'listing' => $this->app->read->page($kind, $filters, $page), 'filters' => $filters, 'products' => in_array($kind,['licenses','offline'],true) ? $this->app->read->products() : []]);
    }

    private function licenseResult(array $result): void
    {
        if (isset($result['secret'])) { $this->render('issued', ['title' => 'Clave comercial generada', 'licenseId' => $result['id'], 'commercialKey' => $result['secret']]); return; }
        $this->redirect('/admin/licenses/' . $result['id']);
    }

    private function confirmIdentity(): void { $this->app->auth->reauthenticate($this->actor, $this->password('password'), Input::text($_POST, 'code', 6)); }
    private function password(string $field): string { $value = $_POST[$field] ?? ''; if (!is_string($value)) { throw new Problem('La contraseña no es válida.'); } return $value; }
    private function redirect(string $path): void { header('Location: ' . $path, true, 303); }
    private function cookieName(): string { return $this->app->config->development() ? 'aibid_admin_dev' : '__Host-license_admin'; }
    private function cookieOptions(int $expires = 0): array { return ['expires' => $expires, 'path' => '/', 'secure' => !$this->app->config->development() || str_starts_with($this->app->config->get('APP_URL'), 'https://'), 'httponly' => true, 'samesite' => 'Lax']; }

    private function setToken(string $token): void
    {
        $this->token = $token;
        setcookie($this->cookieName(), $token, $this->cookieOptions());
        $this->session = $this->app->sessions->load($token);
    }

    private function render(string $template, array $data): void
    {
        (new View())->render($template, $data + ['actor' => $this->actor, 'csrf' => $this->token === '' ? '' : $this->app->sessions->csrf($this->token), 'path' => $this->path, 'now' => $this->app->clock->now()]);
    }
}
