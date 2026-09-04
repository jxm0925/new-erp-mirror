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
		port: 8081
	}
}
