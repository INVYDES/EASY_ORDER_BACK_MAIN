<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Las rutas api/* deben responder 401 JSON cuando falta el token, incluso si el
 * cliente NO manda `Accept: application/json`.
 *
 * Antes de esto el middleware de auth de Laravel intentaba redirigir a la ruta
 * `login` (inexistente) y el handler global convertía esa RouteNotFoundException
 * en un 500 con el mensaje "Route [login] not defined.".
 */
class ApiAuthenticationTest extends TestCase
{
    public function test_protegida_sin_accept_header_devuelve_401_json(): void
    {
        $response = $this->get('/api/user/profile', ['Accept' => 'text/html']);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'No autenticado',
            ]);
    }

    public function test_protegida_sin_headers_devuelve_401_json(): void
    {
        $this->get('/api/user/profile')->assertStatus(401);
    }

    public function test_protegida_con_accept_json_devuelve_401_json(): void
    {
        $this->getJson('/api/user/profile')->assertStatus(401);
    }

    public function test_ruta_publica_sigue_siendo_accesible(): void
    {
        $this->getJson('/api/server-time')->assertStatus(200);
    }

    /**
     * El endpoint de auth de WebSockets vive fuera de api/*, así que necesita el
     * mismo trato: 401 en vez de 500/404 por la ruta `login` inexistente.
     */
    public function test_broadcasting_auth_sin_token_devuelve_401(): void
    {
        $this->post('/broadcasting/auth', [], ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }
}
