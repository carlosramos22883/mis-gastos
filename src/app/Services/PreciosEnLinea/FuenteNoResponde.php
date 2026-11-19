<?php

namespace App\Services\PreciosEnLinea;

use RuntimeException;

/** La tienda no respondió, respondió con error o nos limitó: se pausa esa fuente un tiempo. */
class FuenteNoResponde extends RuntimeException {}
