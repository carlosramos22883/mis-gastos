<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Correo</title>
    @include('emails.partials.styles')
</head>
<body>
    <div class="email-container">
        <div class="logo-section">
            <img src="{{ asset('images/logo-light.png') }}" alt="{{ config('app.name') }}">
        </div>

        <div class="content-section">
            <h2 class="greeting">¡Hola!</h2>
            
            <p class="text-content">
                Gracias por registrarte en {{ config('app.name') }}.
            </p>

            <p class="text-content">
                Para completar tu registro y activar tu cuenta, por favor verifica tu dirección de correo electrónico haciendo clic en el botón de abajo:
            </p>

            <div class="center-text">
                <a href="{{ $actionUrl }}" class="button-primary">
                    Verificar Correo Electrónico
                </a>
            </div>

            <p class="text-content">
                Si no creaste una cuenta, no es necesario realizar ninguna acción.<br>
                Este enlace expirará en 60 minutos.
            </p>
        </div>

        <div class="footer">
            Saludos, El equipo de {{ config('app.name') }}
        </div>
    </div>
</body>
</html>