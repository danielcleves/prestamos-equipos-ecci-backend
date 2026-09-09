#!/bin/sh
set -e

cd /var/www

# 1. Dependencias. En desarrollo el bind mount puede tapar el vendor/ de la imagen,
#    y en un clon limpio el host no tiene vendor/ en absoluto.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ ausente: instalando dependencias..."
    composer install --no-interaction --prefer-dist
fi

# 2. Archivo de entorno. Las variables de docker-compose tienen prioridad sobre
#    el .env (Laravel usa Dotenv immutable), pero APP_KEY debe vivir en el archivo.
if [ ! -f .env ]; then
    echo "[entrypoint] .env ausente: copiando desde .env.example..."
    cp .env.example .env
fi

# 3. Clave de aplicacion.
if ! grep -qE '^APP_KEY=base64:.+' .env; then
    echo "[entrypoint] Generando APP_KEY..."
    php artisan key:generate --no-interaction --force
fi

# 4. Directorios escribibles que git no versiona.
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# 5. Esperar a la base de datos.
#    depends_on/service_healthy solo aplica en "docker compose up": en un
#    "restart", un "docker start" o tras reiniciar la maquina no se respeta,
#    asi que la espera tiene que estar tambien aqui.
espera_bd() {
    intento=0
    maximo=60
    # esperar-bd.php toma la conexion de la configuracion de Laravel (.env), de
    # modo que aqui no se duplican host, usuario ni contrasena, y no hace falta
    # inyectarlos como variables de entorno del contenedor (eso romperia la
    # suite de pruebas, ver el comentario en docker-compose.yml).
    while ! php docker/esperar-bd.php >/dev/null 2>&1; do
        intento=$((intento + 1))
        if [ "$intento" -ge "$maximo" ]; then
            echo "[entrypoint] ERROR: la base de datos no respondio tras $maximo intentos."
            exit 1
        fi
        echo "[entrypoint] Esperando a la base de datos... ($intento/$maximo)"
        sleep 2
    done
    echo "[entrypoint] Base de datos lista."
}
espera_bd

# 6. Migraciones. Es idempotente: si no hay nada pendiente, no hace nada.
echo "[entrypoint] Ejecutando migraciones..."
php artisan migrate --no-interaction --force

echo "[entrypoint] Listo. Arrancando: $*"
exec "$@"
