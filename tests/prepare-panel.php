<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

// Isolated, explicitly requested test data; never run against the application DB.
$fixture = new Aibid\Tests\MySqlFixture();
$app = $fixture->app;
$keys = $fixture->signingKeys();
$keys->generate('panel-random-signing-key', 'license');
$keys->activate('panel-random-signing-key', 'Preparación aislada de prueba del panel');
$adminId = $app->auth->bootstrap('admin@example.test', 'Administración de pruebas', 'Fixture-Password-Only-2026!');
$actor = new Aibid\Application\Actor($adminId, 'superadmin', 'Administración de pruebas');
foreach (['Centro Documental del Norte', 'Archivo Histórico Regional', 'Instituto de Investigación Digital'] as $index => $name) {
    $customer = $app->catalog->saveCustomer($actor, Aibid\Domain\Uuid::create(), null, ['name'=>$name,'legal_name'=>'','contact_name'=>'Contacto de prueba','email'=>'contacto'.$index.'@example.test','phone'=>'','state'=>'active']);
    $issued=$app->licenses->issue($actor, Aibid\Domain\Uuid::create(), ['customer_id'=>$customer['id'],'product_id'=>'gestor_documental','license_type'=>$index===0?'perpetual':'subscription','entitled_release_until'=>gmdate('Y-m-d\TH:i'),'expires_at'=>gmdate('Y-m-d\TH:i',time()+($index===1?15:45)*86400),'features'=>['linked_libraries','managed_libraries','ocr'],'reason'=>'Datos sintéticos para verificar el panel','reference'=>'TEST-PANEL']);
    if ($index===0) {
        $pair=sodium_crypto_sign_keypair();$installation=Aibid\Domain\Uuid::create();
        $challenge=json_decode($app->activations->challenge(['action'=>'activate','product_id'=>'gestor_documental','installation_id'=>$installation,'activation_id'=>null])->body,true);
        $input=['request_id'=>Aibid\Domain\Uuid::create(),'product_id'=>'gestor_documental','installation_id'=>$installation,'license_key'=>$issued['secret'],'installation_public_key'=>Aibid\Domain\Protocol::encode(sodium_crypto_sign_publickey($pair)),'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('c',64),'challenge_id'=>$challenge['challenge_id'],'app_version'=>'panel-test'];
        $input['proof']=Aibid\Domain\Protocol::encode(sodium_crypto_sign_detached(Aibid\Domain\Protocol::proofMessage('activate',$input,$challenge['nonce']),sodium_crypto_sign_secretkey($pair)));
        $app->activations->execute('activate',$input);
        sodium_memzero($pair);
    }
}
$viewer=$app->admins->create($actor,Aibid\Domain\Uuid::create(),['login'=>'viewer@example.test','name'=>'Cuenta de consulta','role'=>'viewer','new_password'=>'Fixture-Viewer-Only-2026!']);
$fixture->owner->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?',[$app->crypto->encrypt('JBSWY3DPEHPK3PXP','mfa:'.$viewer['id']),Aibid\Domain\Uuid::bytes($viewer['id'])]);
$configPath='/private/tmp/aibidlicense-panel-config.php';
$oldMask=umask(0077);
// A test preview must never inherit unrelated DB/runtime settings from a shell.
$contents="<?php\n".'$fixtureValues = '.var_export($fixture->values,true).";\n";
$contents.='foreach (array_keys($fixtureValues) as $fixtureName) { putenv($fixtureName); }'."\nreturn \$fixtureValues;\n";
file_put_contents($configPath,$contents);
umask($oldMask);
echo 'Panel test fixture ready. Configuration: '.$configPath.PHP_EOL;
echo 'Database: '.$fixture->database.' (synthetic test data only).'.PHP_EOL;
