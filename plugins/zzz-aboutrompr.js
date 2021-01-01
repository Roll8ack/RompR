var aboutRompr = function() {

      var about = null;

      return {

      open: function() {
            if (about == null) {
      	     about = browser.registerExtraPlugin("about", "About RompЯ (version "+rompr_version+")", aboutRompr);
                  // randomly change the url to avoid the cache
                  $.get("utils/versioninfo.php?_="+Math.round(Math.random()*10000), function(data) {
                        $("#aboutfoldup").empty().append(data);
                        about.slideToggle('fast', function() {
                              browser.goToPlugin("about");
                              $.get("includes/begging.html?_="+Math.round(Math.random()*10000), function(data) {
                                    $("#aboutfoldup").append(data);
                                    $.get("includes/about.html?_="+Math.round(Math.random()*10000), function(data) {
                                          $("#aboutfoldup").append(data);
                                          $('#fnockulator').load("includes/license.html?_="+Math.round(Math.random()*10000));
                                    });
                              });
                        });
                  });
            } else {
                  browser.goToPlugin("about");
            }
      },

      close: function() {
      	about = null;
      }

}

}();

pluginManager.addPlugin(language.gettext("button_about"), aboutRompr.open, null, null, true);
