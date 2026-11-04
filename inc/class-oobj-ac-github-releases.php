<?php
/**
 * Class OOBJ_AC_GitHub_Releases.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's builds on GitHub: a repo's latest release and its zip. The release workflow
 * (.github/workflows/release.yml) publishes each Version as release v<Version> with <slug>-v<Version>.zip, and marks
 * it the latest. A private repo needs a token that can read its contents; a public one doesn't.
 */
class OOBJ_AC_GitHub_Releases {

	const API = 'https://api.github.com/repos/';

	/**
	 * The repo, "owner/name".
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * The release zip's name before "-v<version>.zip": the plugin's folder.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * A token that can read the repo's contents, or '' for a public repo.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * @param string $repo  The repo, "owner/name".
	 * @param string $slug  The release zip's name before "-v<version>.zip".
	 * @param string $token A token that can read the repo's contents (fine-grained: Contents read-only), or '' for a
	 *                      public repo.
	 */
	public function __construct( string $repo, string $slug, string $token = '' ) {
		$this->repo  = $repo;
		$this->slug  = $slug;
		$this->token = $token;
	}

	/**
	 * The repo from a https://github.com/owner/name URL (the plugin's Update URI), or '' for any other URL.
	 *
	 * @param string $url The URL.
	 */
	public static function repo_from_url( string $url ): string {
		return preg_match( '#^https://github\.com/([\w.-]+/[\w.-]+?)/?$#', $url, $match ) ? $match[1] : '';
	}

	public function configured(): bool {
		return '' !== $this->repo;
	}

	/**
	 * The latest release: its version (the tag without "v"), its page and its zip, which download() fetches.
	 *
	 * @return array{version: string, url: string, package: string}|WP_Error
	 */
	public function latest() {
		$response = wp_remote_get(
			self::API . $this->repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $this->headers( 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'oobj_ac_github_release', sprintf( 'GitHub answered %d for the latest release.', wp_remote_retrieve_response_code( $response ) ) );
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$tag     = is_array( $release ) ? (string) ( $release['tag_name'] ?? '' ) : '';
		if ( ! preg_match( '/^v(\d+(?:\.\d+)*)$/', $tag, $match ) ) {
			return new WP_Error( 'oobj_ac_github_release', 'The latest release has no v<version> tag.' );
		}

		$zip = "{$this->slug}-$tag.zip";
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( ( $asset['name'] ?? '' ) === $zip && $this->is_package( (string) ( $asset['url'] ?? '' ) ) ) {
				return array(
					'version' => $match[1],
					'url'     => (string) ( $release['html_url'] ?? '' ),
					'package' => $asset['url'],
				);
			}
		}
		return new WP_Error( 'oobj_ac_github_release', "Release $tag has no $zip." );
	}

	/**
	 * Whether a URL is one of this repo's release files (the API URL latest() gives as the package).
	 *
	 * @param string $url The URL.
	 */
	public function is_package( string $url ): bool {
		return (bool) preg_match( '#^' . preg_quote( self::API . $this->repo, '#' ) . '/releases/assets/\d+$#', $url );
	}

	/**
	 * Downloads a release file to a temporary file, which the caller deletes. GitHub answers the request for it with
	 * a redirect to a signed storage URL. A token goes only to GitHub: the signed URL is fetched without it (the
	 * storage refuses a request that has both).
	 *
	 * @param string $package The file's API URL, from latest().
	 * @return string|WP_Error The temporary file's path.
	 */
	public function download( string $package ) {
		if ( ! $this->is_package( $package ) ) {
			return new WP_Error( 'oobj_ac_github_download', 'Not a release file of ' . $this->repo . '.' );
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => $this->headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! is_string( $location ) || ! str_starts_with( $location, 'https://' ) ) {
			return new WP_Error( 'oobj_ac_github_download', sprintf( 'GitHub answered %d for the release file, without a download link.', wp_remote_retrieve_response_code( $response ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		return download_url( $location, 300 );
	}

	/**
	 * Request headers for the GitHub API, with the token when there is one.
	 *
	 * @param string $accept The media type wanted.
	 * @return array<string, string>
	 */
	private function headers( string $accept ): array {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}
}
