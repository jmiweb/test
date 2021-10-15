// Define gulp tasks.
module.exports = function (gulp, plugins, options) {

  'use strict';

  var fs = require('fs');

  // Processor for linting is assigned to options so it can be reused later.
  options.processors = [
    // Options are defined in .stylelintrc.yaml file.
    plugins.stylelint(options.stylelint),
    plugins.reporter(options.processors.reporter)
  ];

  // Post CSS options.
  options.postcssOptions = [
    plugins.autoprefixer(options.autoprefixer),
    plugins.mqpacker({sort: true})
  ];

  // Gulp error handler.
  if (options.onError) {
    options.onErrorCallback = function (err) {
      plugins.notify.onError({
        title: options.onError.title + err.plugin,
        message: options.onError.message,
        sound: options.onError.sound
      })(err);
      this.emit('end');
    };
  }

  // Defining gulp tasks.

  // Load theme _functions.scss partial if it exist so we can use them
  // in theme-options.yml.
  // Clone themes array from parentTheme and add current theme because we need
  // to check it also.
  var allThemes = JSON.parse(JSON.stringify(options.parentTheme));
  allThemes.push({name: 'current', path: './'});
  function loadFunctions(chunk, enc, callback) {
    for (var i = 0; i < allThemes.length; ++i) {
      var path = allThemes[i].path + 'sass/_functions.scss';
      if (fs.existsSync(path)) {
        var content = fs.readFileSync(path);
        if (content) {
          // We are injecting to the start of the buffer so functions are
          // before SASS vars that are coming from yaml.
          chunk.contents = Buffer.concat([content, chunk.contents], content.length + chunk.contents.length);
        }
      }
    }

    callback(null, chunk);
  }

  gulp.task('sass', function () {
    var task = gulp.src(options.sass.src + '/**/*.scss');

    if (options.onError) {
      // Error management in gulp.
      task.pipe(plugins.plumber({errorHandler: options.onErrorCallback}));
    }

    task.pipe(plugins.sassInject(options.sass.injectVariables))
      .pipe(plugins.through.obj(loadFunctions))
      .pipe(plugins.sassGlob())
      .pipe(plugins.sass({
        outputStyle: 'expanded',
        includePaths: options.sass.includePaths
      }))
      .pipe(plugins.postcss(options.postcssOptions))
      .pipe(plugins.stripCssComments())
      .pipe(gulp.dest(options.sass.dest));
    return task;
  });

  gulp.task('sass:dev', function () {
    var task = gulp.src(options.sass.src + '/**/*.scss', {sourcemaps: true});
    if (options.onError) {
      // Error management in gulp.
      task.pipe(plugins.plumber({errorHandler: options.onErrorCallback}));
    }
    task.pipe(plugins.sassInject(options.sass.injectVariables))
      .pipe(plugins.through.obj(loadFunctions))
      .pipe(plugins.sassGlob())
      .pipe(plugins.sass({
        outputStyle: 'expanded',
        includePaths: options.sass.includePaths,
      }))
      .pipe(plugins.debug())
      .pipe(plugins.postcss(options.postcssOptions))
      .pipe(gulp.dest(options.sass.dest, {sourcemaps: '.'}));
    return task;
  });

  gulp.task('sass:lint', function () {
    return gulp.src(options.sass.src + '/**/*.scss')
      .pipe(plugins.postcss(options.processors, {syntax: plugins.syntax_scss}));
  });

  gulp.task('clean:css', function () {
    return plugins.del([
      'css/**/*'
    ]);
  });

  // Gulp run all tasks.

  gulp.task('default', gulp.series('clean:css', 'sass:lint', 'sass'));
  gulp.task('prod', gulp.series('default'));
  gulp.task('dev', gulp.series('sass:dev'));
  gulp.task('clean', gulp.series('clean:css'));

  // Gulp watches.

  // gulp.task('watch', function () {
  //   gulp.watch(options.sass.src + '/**/*.scss', ['sass']);
  // });
  
  // gulp.task('watch:dev', function () {
  //   gulp.watch(options.sass.src + '/**/*.scss', ['sass:dev']);
  // });

  // Gulp 4.0 syntax
  
  gulp.task('watch', function(){
    gulp.watch(options.sass.src + '/**/*.scss', gulp.series('sass'));
  });

  gulp.task('watch:dev', function () {
    gulp.watch(options.sass.src + '/**/*.scss', gulp.series('sass:dev'));
  });

};
