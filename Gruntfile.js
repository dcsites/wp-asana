module.exports = function(grunt) {
  grunt.initConfig({
      pkg: grunt.file.readJSON('package.json'),

      // SASS compile
      sass: {
        options: {
          sourceMap: true,
          outputStyle: 'compressed'
        },
        dist: {
            files: {
                'css/style.css' : 'scss/style.scss'
            }
        }
      },
      // PHP code styling check.
      phpcs: {
        options: {
            standard: 'WordPress-Core',
        },
        theme: {
            src: ['./**/*.php']
        },
      },

      phpcbf: {
        options: {
          standard: 'WordPress-Core'
        },
        theme: {
          files: {
            src: ['*.php'],
          },
        },
      },

      // Watch tasks.
      watch: {
        css: {
          files: ['scss/**/*.scss'],
          tasks: ["sass"]
        },
      },
    });

  // NPM load tasks.
  grunt.loadNpmTasks('grunt-phpcs');
  grunt.loadNpmTasks('grunt-contrib-watch');

  // NPM register tasks.
  grunt.registerTask('default', ['sass']);
  grunt.registerTask('test',  ['phpcs']);
  grunt.registerTask('clean', ['phpcbf']);
  grunt.registerTask('build', ['phpcs', 'sass']);

  require('load-grunt-tasks')(grunt);
};