<?php
declare(strict_types = 1);

namespace Sayamaapps\Kizami;

/** The site's setup is unusable for Kizami; the message tells the operator what to change. */
final class SetupError extends \RuntimeException
{
}
