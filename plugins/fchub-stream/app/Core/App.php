<?php
/**
 * App Facade
 *
 * Provides static access to the application container instance.
 *
 * @package FCHub_Stream
 * @since 1.0.0
 */

namespace FCHubStream\App\Core;

use FCHubStream\App\Core\AppTrait;

/**
 * App Class
 *
 * Static facade for accessing the application container.
 * Uses AppTrait to provide getInstance and make methods.
 *
 * @since 1.0.0
 */
class App {
	use AppTrait;
}
