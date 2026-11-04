/**
 * For the front-end script tests: runs a script from assets/ as the page's <script> would.
 */
const path = require( 'path' );

/**
 * Runs assets/{name}.js afresh.
 *
 * @param {string} name The script's path under assets/, without .js.
 */
function run( name ) {
	jest.isolateModules( () => {
		require( path.join( __dirname, '../../assets', name + '.js' ) );
	} );
}

module.exports = { run };
