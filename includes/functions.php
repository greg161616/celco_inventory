<?php
declare(strict_types=1);

final class InputError extends RuntimeException {}
function db(): PDO { return $GLOBALS['pdo']; }
function run_sql(string $sql, array $params = []): PDOStatement {
    $statement = db()->prepare($sql);
    $statement->execute(array_values($params));
    return $statement;
}
function rows(string $sql, array $params = []): array { return run_sql($sql,$params)->fetchAll(); }
function row(string $sql, array $params = []): ?array { return run_sql($sql,$params)->fetch() ?: null; }
function scalar(string $sql, array $params = []): mixed { return run_sql($sql,$params)->fetchColumn(); }
function inserted_id(): int { return (int)db()->lastInsertId(); }
function atomic(callable $work): mixed {
    if (db()->inTransaction()) return $work();
    db()->beginTransaction();
    try {
        $result = $work();
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}
function setting(string $key, string $fallback=''): string {
    static $settings = null;
    $settings ??= array_column(rows('SELECT setting_key,setting_value FROM settings'),'setting_value','setting_key');
    return $settings[$key] ?? $fallback;
}
function app_url(string $path=''): string {
    $base = getenv('CELCO_BASE_PATH');
    if ($base === false) {
        $root = str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
        $app = str_replace('\\','/',APP_ROOT);
        $base = $root && str_starts_with(strtolower($app.'/' ),strtolower(rtrim($root,'/').'/')) ? substr($app,strlen(rtrim($root,'/'))) : '';
    }
    return rtrim('/'.trim($base,'/'),'/').'/'.ltrim($path,'/');
}
function currency_choices(): array {
    return ['PHP'=>'Philippine peso (₱)','USD'=>'US dollar ($)','EUR'=>'Euro (€)','GBP'=>'British pound (£)','JPY'=>'Japanese yen (¥)'];
}
function currency_symbol(string $currency): string {
    return match($currency){'PHP'=>'₱','USD'=>'$','EUR'=>'€','GBP'=>'£','JPY'=>'¥',default=>$currency};
}
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect_to(string $path): never { header('Location: '.app_url($path), true,303); exit; }
function is_api(): bool { return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/'); }
function json_response(bool $success,string $message,mixed $data=null,int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(compact('success','message','data'), JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function friendly_error(Throwable $error): string {
    if ($error instanceof InputError) return $error->getMessage();
    if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) return 'That code, number or assignment already exists.';
    if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1451) return 'This record is in use and must be retained.';
    error_log(get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().' code '.$error->getCode());
    return 'The operation could not be completed. Refresh and try again.';
}
function app_exception(Throwable $error): void {
    $message=friendly_error($error);
    if (PHP_SAPI==='cli') {fwrite(STDERR,$message."\n"); exit(1);}
    $status=$error instanceof InputError ? 422 : 500;
    if(is_api()) json_response(false,$message,null,$status);
    http_response_code($status);
    echo '<main lang="en"><h1>Unable to complete request</h1><p>'.e($message).'</p><a href="'.e(app_url()).'">Return to workspace</a></main>';
}
function post_action(callable $work,string $path): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    verify_csrf();
    try { $target=$work(); $_SESSION['flash']=['success','Changes saved.']; redirect_to(is_string($target)?$target:$path); }
    catch(Throwable $error) { $_SESSION['flash']=['danger',friendly_error($error)]; redirect_to($path); }
}
function doc_number(string $kind): string {
    $prefix=setting('prefix_'.strtolower($kind),substr($kind,0,4));
    return $prefix.'-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
}
function lock_rows(string $table,array $ids): void {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); sort($ids,SORT_NUMERIC);
    foreach ($ids as $id) { if(!scalar("SELECT id FROM $table WHERE id=? FOR UPDATE",[$id])) throw new InputError('A referenced record no longer exists.'); }
}

