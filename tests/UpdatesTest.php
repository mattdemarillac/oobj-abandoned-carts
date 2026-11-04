<?php
/**
 * Plugin updates from the GitHub releases (OOBJ_AC_Updates, OOBJ_AC_GitHub_Releases). GitHub is mocked.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class UpdatesTest extends OOBJ_AC_TestCase {

	const REPO    = 'mattdemarillac/oobj-abandoned-carts';
	const SLUG    = 'oobj-abandoned-carts';
	const PLUGIN  = 'oobj-abandoned-carts/oobj-abandoned-carts.php';
	const ASSET   = 'https://api.github.com/repos/mattdemarillac/oobj-abandoned-carts/releases/assets/700000001';
	const STORAGE = 'https://release-assets.githubusercontent.com/github-production-release-asset/1/plugin.zip?sig=abc';

	/**
	 * Requests made: URL => request args.
	 *
	 * @var array<string, array>
	 */
	private $requests = array();

	/**
	 * Mocked responses by URL: array( code, body, headers ).
	 *
	 * @var array<string, array>
	 */
	private $responses = array();

	public function set_up() {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest' => array( 200, wp_json_encode( $this->release( 'v9.1.0' ) ), array() ),
			self::ASSET   => array( 302, '', array( 'location' => self::STORAGE ) ),
			self::STORAGE => array( 200, 'zip', array() ),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ) );
		parent::tear_down();
	}

	public function mock_http( $pre, $args, $url ) {
		$this->requests[ $url ] = $args;
		if ( ! isset( $this->responses[ $url ] ) ) {
			return new WP_Error( 'http_request_failed', "Unexpected request: $url" );
		}
		list( $code, $body, $headers ) = $this->responses[ $url ];
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => $body,
			'headers'  => $headers,
			'cookies'  => array(),
		);
	}

	private function release( $tag, $asset_name = null ) {
		return array(
			'tag_name' => $tag,
			'html_url' => 'https://github.com/' . self::REPO . "/releases/tag/$tag",
			'assets'   => array(
				array(
					'name' => self::SLUG . "-$tag.tar.gz",
					'url'  => 'https://api.github.com/repos/' . self::REPO . '/releases/assets/700000002',
				),
				array(
					'name' => $asset_name ?? self::SLUG . "-$tag.zip",
					'url'  => self::ASSET,
				),
			),
		);
	}

	private function updates( $token = 'secret', $environment = 'production', $repo = self::REPO ) {
		return new OOBJ_AC_Updates( new OOBJ_AC_GitHub_Releases( $repo, self::SLUG, $token ), self::PLUGIN, $environment );
	}

	public function test_the_plugin_header_names_the_repo_as_update_uri() {
		$uri = OOBJ_AC_Plugin::header( 'Update URI' );

		$this->assertSame( 'github.com', wp_parse_url( $uri, PHP_URL_HOST ), 'WordPress asks update_plugins_github.com' );
		$this->assertSame( self::REPO, OOBJ_AC_GitHub_Releases::repo_from_url( $uri ) );
		$updates = oobj_abandoned_carts( OOBJ_AC_Updates::class );
		$this->assertSame( 10, has_filter( 'update_plugins_github.com', array( $updates, 'check' ) ) );
		$this->assertSame( 10, has_filter( 'upgrader_pre_download', array( $updates, 'download' ) ) );
	}

	public function test_repo_from_url_takes_only_github_repo_urls() {
		$this->assertSame( 'owner/repo.name', OOBJ_AC_GitHub_Releases::repo_from_url( 'https://github.com/owner/repo.name/' ) );
		$this->assertSame( '', OOBJ_AC_GitHub_Releases::repo_from_url( 'https://github.com/owner' ) );
		$this->assertSame( '', OOBJ_AC_GitHub_Releases::repo_from_url( 'https://example.com/owner/repo' ) );
		$this->assertSame( '', OOBJ_AC_GitHub_Releases::repo_from_url( '' ) );
	}

	public function test_offers_the_latest_release() {
		$update = $this->updates()->check( false, array(), self::PLUGIN );

		$this->assertSame(
			array(
				'slug'    => self::SLUG,
				'version' => '9.1.0',
				'url'     => 'https://github.com/' . self::REPO . '/releases/tag/v9.1.0',
				'package' => self::ASSET,
			),
			$update
		);
		$args = $this->requests[ 'https://api.github.com/repos/' . self::REPO . '/releases/latest' ];
		$this->assertSame( 'Bearer secret', $args['headers']['Authorization'] );
	}

	public function test_a_public_repo_needs_no_token() {
		$this->assertSame( '9.1.0', $this->updates( '' )->check( false, array(), self::PLUGIN )['version'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[ 'https://api.github.com/repos/' . self::REPO . '/releases/latest' ]['headers'] );
	}

	public function test_wordpress_lists_a_newer_release_as_an_update() {
		$updates = $this->updates();
		add_filter( 'update_plugins_github.com', array( $updates, 'check' ), 9, 3 );
		add_filter(
			'all_plugins',
			fn( $plugins ) => $plugins + array(
				self::PLUGIN => get_plugin_data( OOBJ_ABANDONED_CARTS_FILE, false, false ),
			)
		);
		// WordPress.org knows no update for the plugin.
		$this->responses['https://api.wordpress.org/plugins/update-check/1.1/'] = array( 200, '{"plugins":[],"no_update":[],"translations":[]}', array() );
		delete_site_transient( 'update_plugins' );

		wp_update_plugins();

		$response = get_site_transient( 'update_plugins' )->response[ self::PLUGIN ] ?? null;
		$this->assertSame( '9.1.0', $response->new_version ?? null );
		$this->assertSame( self::ASSET, $response->package ?? null );
	}

	public function test_offers_nothing_on_a_local_site_without_a_repo_or_for_another_plugin() {
		$this->assertFalse( $this->updates( 'secret', 'local' )->check( false, array(), self::PLUGIN ) );
		$this->assertFalse( $this->updates( 'secret', 'production', '' )->check( false, array(), self::PLUGIN ) );
		$this->assertFalse( $this->updates()->check( false, array(), 'akismet/akismet.php' ) );
		$this->assertSame( array( 'found' ), $this->updates()->check( array( 'found' ), array(), self::PLUGIN ), 'Another filter found one' );
		$this->assertSame( array(), $this->requests, 'GitHub is not asked' );
	}

	public function test_offers_nothing_when_github_fails_or_the_release_has_no_zip() {
		$latest = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

		$this->responses[ $latest ] = array( 401, '{"message":"Bad credentials"}', array() );
		$this->assertFalse( $this->updates()->check( false, array(), self::PLUGIN ) );

		$this->responses[ $latest ] = array( 200, wp_json_encode( $this->release( 'v9.1.0', self::SLUG . '-v9.0.0.zip' ) ), array() );
		$this->assertFalse( $this->updates()->check( false, array(), self::PLUGIN ) );

		$this->responses[ $latest ] = array( 200, wp_json_encode( $this->release( 'latest' ) ), array() );
		$this->assertFalse( $this->updates()->check( false, array(), self::PLUGIN ) );
	}

	public function test_downloads_the_zip_sending_the_token_only_to_github() {
		$file = $this->updates()->download( false, self::ASSET );

		$this->assertIsString( $file );
		$this->assertFileExists( $file );
		wp_delete_file( $file );
		$this->assertSame( 'Bearer secret', $this->requests[ self::ASSET ]['headers']['Authorization'] );
		$this->assertSame( 'application/octet-stream', $this->requests[ self::ASSET ]['headers']['Accept'] );
		$this->assertSame( 0, $this->requests[ self::ASSET ]['redirection'], 'Redirect not followed with the token' );
		$this->assertArrayNotHasKey( 'Authorization', (array) $this->requests[ self::STORAGE ]['headers'] );
	}

	public function test_leaves_other_downloads_to_wordpress() {
		$updates = $this->updates();

		$this->assertFalse( $updates->download( false, 'https://downloads.wordpress.org/plugin/akismet.zip' ) );
		$this->assertFalse( $updates->download( false, 'https://api.github.com/repos/someone/else/releases/assets/1' ) );
		$this->assertFalse( $this->updates( 'secret', 'local' )->download( false, self::ASSET ) );
		$this->assertSame( '/tmp/x.zip', $updates->download( '/tmp/x.zip', self::ASSET ), 'Another filter already downloaded it' );
		$this->assertSame( array(), $this->requests );
	}

	public function test_github_unreachable_is_an_error() {
		unset( $this->responses[ 'https://api.github.com/repos/' . self::REPO . '/releases/latest' ], $this->responses[ self::ASSET ] );
		$releases = new OOBJ_AC_GitHub_Releases( self::REPO, self::SLUG, 'secret' );

		$this->assertSame( 'http_request_failed', $releases->latest()->get_error_code() );
		$this->assertSame( 'http_request_failed', $releases->download( self::ASSET )->get_error_code() );
	}

	public function test_only_its_own_release_files_are_downloaded() {
		$error = ( new OOBJ_AC_GitHub_Releases( self::REPO, self::SLUG ) )->download( 'https://api.github.com/repos/someone/else/releases/assets/1' );

		$this->assertSame( 'oobj_ac_github_download', $error->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_download_fails_without_a_redirect() {
		$this->responses[ self::ASSET ] = array( 404, '', array() );

		$this->assertWPError( $this->updates()->download( false, self::ASSET ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_token_comes_from_wp_config() {
		$this->assertSame( '', OOBJ_AC_Updates::token(), 'None: a public repo' );
		define( 'OOB_GITHUB_TOKEN', 'theme-token' );
		$this->assertSame( '', OOBJ_AC_Updates::token(), 'Not another project\'s' );
		define( 'OOBJ_AC_GITHUB_TOKEN', 'plugin-token' );
		$this->assertSame( 'plugin-token', OOBJ_AC_Updates::token() );
	}
}
