<?php
/**
 * Class OOBJ_AC_Updates.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Updates the plugin from its GitHub releases, in Dashboard → Updates and Plugins like any other plugin. The plugin
 * header's Update URI names the repo; WordPress asks this module about a plugin with a github.com Update URI
 * (update_plugins_github.com) and offers the update when the latest release's version is newer than Version.
 *
 * A public repo needs nothing more. A private one (a fork, say) needs a token that can read it, in wp-config.php:
 * define( 'OOBJ_AC_GITHUB_TOKEN', '…' ). Off on a local site, whose plugin folder is a working copy (an update would
 * replace it).
 */
class OOBJ_AC_Updates implements OOBJ_AC_Module {

	/**
	 * The plugin's releases.
	 *
	 * @var OOBJ_AC_GitHub_Releases
	 */
	private $releases;

	/**
	 * The plugin's file, relative to the plugins folder (plugin_basename()).
	 *
	 * @var string
	 */
	private $plugin;

	/**
	 * The environment type (wp_get_environment_type()).
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * @param OOBJ_AC_GitHub_Releases $releases    The plugin's releases.
	 * @param string                  $plugin      The plugin's file, relative to the plugins folder.
	 * @param string                  $environment The environment type (wp_get_environment_type()).
	 */
	public function __construct( OOBJ_AC_GitHub_Releases $releases, string $plugin, string $environment ) {
		$this->releases    = $releases;
		$this->plugin      = $plugin;
		$this->environment = $environment;
	}

	/**
	 * The token for a private repo, from wp-config.php (OOBJ_AC_GITHUB_TOKEN), or ''.
	 */
	public static function token(): string {
		return defined( 'OOBJ_AC_GITHUB_TOKEN' ) && is_string( OOBJ_AC_GITHUB_TOKEN ) ? OOBJ_AC_GITHUB_TOKEN : '';
	}

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 2 );
	}

	public function enabled(): bool {
		return 'local' !== $this->environment && $this->releases->configured();
	}

	/**
	 * The latest release, for WordPress's plugin update check. WordPress compares its version with the installed one.
	 *
	 * @param array|false $update      An update another filter found, or false.
	 * @param array       $plugin_data The plugin's headers.
	 * @param string      $plugin_file The plugin's file, relative to the plugins folder.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( $update || $this->plugin !== $plugin_file || ! $this->enabled() ) {
			return $update;
		}

		$latest = $this->releases->latest();
		if ( is_wp_error( $latest ) ) {
			return $update;
		}
		return array(
			'slug'    => dirname( $this->plugin ),
			'version' => $latest['version'],
			'url'     => $latest['url'],
			'package' => $latest['package'],
		);
	}

	/**
	 * Downloads the update's zip itself: from a private repo it needs the token, which download_url() can't send.
	 *
	 * @param false|string|WP_Error $reply   A download another filter made, or false.
	 * @param string                $package The package URL.
	 * @return false|string|WP_Error
	 */
	public function download( $reply, $package ) {
		if ( false !== $reply || ! $this->enabled() || ! $this->releases->is_package( (string) $package ) ) {
			return $reply;
		}
		return $this->releases->download( (string) $package );
	}
}
