const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'hotspot-editor': path.resolve( __dirname, 'src/admin/hotspot-editor/index.js' ),
		's3-uploader': path.resolve( __dirname, 'src/admin/s3-uploader/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/admin/js' ),
		filename: '[name].js',
	},
};
