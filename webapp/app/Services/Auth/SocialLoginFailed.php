<?php

namespace App\Services\Auth;

use RuntimeException;

/** A provider sign-in that did not work, with a message that can be shown to the person. */
class SocialLoginFailed extends RuntimeException {}
