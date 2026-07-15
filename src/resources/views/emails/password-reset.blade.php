<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperación de Contraseña</title>
    @include('emails.partials.styles')
</head>
<body>
    <div class="email-container">
        <!-- Logo -->
        <div class="logo-section">
            <img src="{{ asset('images/logo.png') }}" alt="Mis Gastos">
        </div>

        <!-- Contenido -->
        <div class="content-section">
            <h2 class="greeting">¡Hola!</h2>
            
            <p class="text-content">
                Recibimos una solicitud para restablecer tu contraseña.
            </p>

            <p class="text-content">
                Si fuiste tú, usa el siguiente token para restablecer tu contraseña desde la aplicación móvil:
            </p>

            <!-- Token destacado - Al hacer clic se selecciona SOLO el token -->
            <div class="token-box">
                <div class="label">🔑 Tu token de recuperación:</div>
                <div class="token-text" title="Haz clic para seleccionar el token">{{ $token }}</div>
                <div class="copy-hint">Haz clic sobre el token y presiona Ctrl+C (o Cmd+C en Mac) para copiar</div>
            </div>

            <p class="text-content">
                Pégalo en la aplicación móvil cuando te lo solicite.
            </p>

            <p class="text-content">
                O si lo prefieres, puedes realizar esta misma acción desde el sitio web:
            </p>

            <!-- Botón para web -->
            <div class="center-text">
                <a href="{{ $actionUrl }}" class="button">
                    Restablecer Contraseña
                </a>
            </div>

            <p class="footer-text">
                Si no solicitaste un restablecimiento de contraseña, no es necesario realizar ninguna acción.<br>
                Este token expirará en 60 minutos.
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            Saludos, El equipo de Mis Gastos
        </div>
    </div>
</body>
</html>