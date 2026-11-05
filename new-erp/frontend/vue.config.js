module.exports = {
	publicPath: './',
	configureWebpack: {
		resolve: {
		  alias: {
			'vue$': 'vue/dist/vue.esm.js'
		  }
		}
	},
	devServer: {
		host: '0.0.0.0',
		port: 8081,
		proxy: {
			'/api': {
				target: 'http://127.0.0.1:8011',
				changeOrigin: true
			}
		}
	}
}
