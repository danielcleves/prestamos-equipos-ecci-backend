<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El throttle de /api/login se apoya en la cache. Sin limpiarla, los
        // intentos de una prueba se acumulan en la siguiente y provocan 429
        // en pruebas que no tienen nada que ver con el limite.
        Cache::flush();
    }

    private function crearUsuario(string $password = 'password123'): User
    {
        return User::factory()->create([
            'email' => 'jose@ecci.edu.co',
            'password' => Hash::make($password),
        ]);
    }

    public function test_login_con_credenciales_validas_devuelve_token_y_usuario(): void
    {
        $user = $this->crearUsuario();

        $response = $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'roles'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'jose@ecci.edu.co');

        $this->assertNotEmpty($response->json('token'));

        // El token debe quedar persistido para poder revocarlo despues.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_nunca_expone_el_hash_de_la_contrasena(): void
    {
        $this->crearUsuario();

        $response = $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_login_devuelve_los_roles_del_usuario(): void
    {
        $user = $this->crearUsuario();
        Role::create(['name' => 'encargado']);
        $user->assignRole('encargado');

        $response = $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.roles', ['encargado']);
    }

    public function test_login_con_contrasena_incorrecta_es_rechazado(): void
    {
        $this->crearUsuario();

        $response = $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'contrasena-equivocada',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email']]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rechaza_usuario_desactivado(): void
    {
        User::factory()->inactive()->create([
            'email' => 'jose@ecci.edu.co',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email']]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_con_correo_inexistente_devuelve_el_mismo_error(): void
    {
        $this->crearUsuario();

        $inexistente = $this->postJson('/api/login', [
            'email' => 'nadie@ecci.edu.co',
            'password' => 'password123',
        ]);

        Cache::flush();

        $contrasenaMala = $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'contrasena-equivocada',
        ]);

        // Mensajes identicos: si difirieran, se podria averiguar que correos
        // estan registrados probando uno por uno.
        $this->assertSame(
            $inexistente->json('errors.email'),
            $contrasenaMala->json('errors.email'),
        );
    }

    public function test_login_exige_correo_y_contrasena(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_se_bloquea_tras_demasiados_intentos(): void
    {
        $this->crearUsuario();

        // El limite es de 6 por minuto: los 6 primeros fallan con 422.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', [
                'email' => 'jose@ecci.edu.co',
                'password' => 'contrasena-equivocada',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'jose@ecci.edu.co',
            'password' => 'contrasena-equivocada',
        ])->assertStatus(429);
    }

    public function test_me_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonStructure(['message']);
    }

    public function test_me_sin_token_devuelve_401_aunque_no_pidan_json(): void
    {
        // get() en lugar de getJson(): no envia "Accept: application/json".
        // Sin el manejador de AuthenticationException, Laravel intentaba
        // redirigir a la ruta 'login' (inexistente) y devolvia un 500 con la
        // traza completa. El frontend no siempre manda esa cabecera.
        $this->get('/api/me')
            ->assertStatus(401)
            ->assertJsonStructure(['message']);
    }

    public function test_me_con_token_devuelve_el_usuario_autenticado(): void
    {
        $user = $this->crearUsuario();
        $token = $user->createToken('pruebas')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'jose@ecci.edu.co');
    }

    public function test_logout_revoca_el_token_usado(): void
    {
        $user = $this->crearUsuario();
        $token = $user->createToken('pruebas')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Dentro de una misma prueba el guard conserva el usuario ya resuelto,
        // asi que sin esto la siguiente peticion pasaria por autenticada aunque
        // el token ya no exista. En produccion cada peticion parte de cero.
        $this->app['auth']->forgetGuards();

        // El token revocado ya no sirve para nada.
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_no_afecta_a_los_demas_dispositivos(): void
    {
        $user = $this->crearUsuario();
        $movil = $user->createToken('movil')->plainTextToken;
        $web = $user->createToken('web')->plainTextToken;

        $this->withToken($movil)->postJson('/api/logout')->assertOk();

        // Sin esto la peticion siguiente reutilizaria el usuario ya resuelto y
        // la prueba pasaria sin comprobar realmente el token del otro token.
        $this->app['auth']->forgetGuards();

        // La sesion del otro dispositivo sigue viva.
        $this->withToken($web)->getJson('/api/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Y el token revocado sigue sin servir.
        $this->app['auth']->forgetGuards();
        $this->withToken($movil)->getJson('/api/me')->assertStatus(401);
    }
}
