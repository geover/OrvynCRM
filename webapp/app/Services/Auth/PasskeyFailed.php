<?php

namespace App\Services\Auth;

use RuntimeException;

/** A passkey that was refused, with a message that can be shown to the person. */
class PasskeyFailed extends RuntimeException {}
