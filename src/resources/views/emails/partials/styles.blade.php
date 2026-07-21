@php
// ============================================
// VARIABLES DE EMAIL
// Sincronizadas con resources/css/variables.css
// ============================================
$emailColors = [
    'primary' => '#0a0a5e',
    'primaryHover' => '#08084a',
    'secondary' => '#6b7280',
    'success' => '#0B6E4F',
    'successHover' => '#2E8B57',
    'warning' => '#f59e0b',
    'danger' => '#ef4444',
    'info' => '#3b82f6',
    'bgLight' => '#f3f4f6',
    'bgDark' => '#1f2937',
    'textLight' => '#1f2937',
    'textDark' => '#f9fafb',
    'border' => '#e5e7eb',
];
@endphp

<style>
    body {
        font-family: Arial, sans-serif;
        background-color: {{ $emailColors['bgLight'] }};
        margin: 0;
        padding: 0;
    }
    .email-container {
        max-width: 600px;
        margin: 20px auto;
        background-color: #ffffff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .logo-section {
        background-color: #ffffff;
        padding: 20px 20px 10px 20px;
        text-align: center;
    }
    .logo-section img {
        max-width: 120px;
        height: auto;
    }
    .content-section {
        padding: 10px 30px 30px 30px;
    }
    .token-box {
        background-color: {{ $emailColors['bgLight'] }};
        border: 2px dashed {{ $emailColors['primary'] }};
        padding: 15px;
        text-align: center;
        margin: 20px 0;
        border-radius: 6px;
    }
    .token-text {
        font-size: 11px;
        font-weight: bold;
        color: {{ $emailColors['primary'] }};
        letter-spacing: 1px;
        word-break: break-all;
        font-family: 'Courier New', monospace;
        display: inline-block;
        padding: 8px 12px;
        background-color: #ffffff;
        border-radius: 4px;
        cursor: pointer;
        user-select: all;
    }
    .token-text:hover {
        background-color: #e0e7ff;
    }
    .copy-hint {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 8px;
        font-style: italic;
    }
    
    /* BOTÓN PRIMARIO - IDÉNTICO AL COMPONENTE BLADE */
    .button-primary {
        display: inline-block;
        background-color: {{ $emailColors['primary'] }};
        color: #ffffff !important;
        text-decoration: none;
        padding: 12px 28px;
        border-radius: 9999px; /* Redondeado completo */
        margin: 15px 0;
        text-align: center;
        font-weight: bold;
        font-size: 14px;
        letter-spacing: 0.05em;
        transition: background-color 0.15s ease-in-out;
    }
    .button-primary:hover {
        background-color: {{ $emailColors['primaryHover'] }};
    }
    
    /* Botón secundario */
    .button-secondary {
        display: inline-block;
        background-color: {{ $emailColors['secondary'] }};
        color: #ffffff !important;
        text-decoration: none;
        padding: 12px 28px;
        border-radius: 9999px;
        margin: 15px 0;
        text-align: center;
        font-weight: bold;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    /* Botón de peligro */
    .button-danger {
        display: inline-block;
        background-color: {{ $emailColors['danger'] }};
        color: #ffffff !important;
        text-decoration: none;
        padding: 12px 28px;
        border-radius: 9999px;
        margin: 15px 0;
        text-align: center;
        font-weight: bold;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .footer {
        background-color: {{ $emailColors['bgLight'] }};
        padding: 15px 20px;
        text-align: center;
        font-size: 11px;
        color: {{ $emailColors['secondary'] }};            
    }
    .greeting {
        color: {{ $emailColors['textLight'] }};
        margin-top: 0;
        font-size: 20px;
    }
    .text-content {
        color: #4b5563;
        line-height: 1.5;
        font-size: 14px;
        margin: 10px 0;
    }
    .center-text {
        text-align: center;
    }
    .label {
        color: {{ $emailColors['secondary'] }};
        font-size: 12px;
        margin-bottom: 8px;
    }
    .footer-text {
        color: {{ $emailColors['secondary'] }};
        font-size: 12px;
        margin-top: 20px;
    }
</style>