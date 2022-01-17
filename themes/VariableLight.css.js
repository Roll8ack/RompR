themeManager.vl_timer = null;

themeManager.vl_update = function() {
	clearTimeout(themeManager.vl_timer);
	var d = new Date();
	var hour = d.getHours();
	themeManager.setBgCss(hour);

	var m = d.getMinutes();
	var tim = (60-m) * 60000;
	debug.log('THEMEMANAGER', 'Will update colours in',tim,'ms');
	themeManager.vl_timer = setTimeout(themeManager.vl_update, tim);
}

themeManager.setBgCss = function(hour) {
	const hourColours = {
		0:  'linear-gradient(#001018, #000000)',
		1:  'linear-gradient(#001018, #000000)',
		2:  'linear-gradient(#001220, #000000)',
		3:  'linear-gradient(#001321, #000010)',
		4:  'linear-gradient(#001728, #001018)',
		5:  'linear-gradient(#021728, #051928)',
		6:  'linear-gradient(#021728, #181928)',
		7:  'linear-gradient(#021728, #272028)',
		8:  'linear-gradient(#021830, #201D30)',
		9:  'linear-gradient(#041B32, #101D30)',
		10: 'linear-gradient(#051F32, #101F32)',
		11: 'linear-gradient(#142034, #101F32)',
		12: 'linear-gradient(#14253C, #14263D)',
		13: 'linear-gradient(#14253E, #142645)',
		14: 'linear-gradient(#142640, #142848)',
		15: 'linear-gradient(#142640, #142950)',
		16: 'linear-gradient(#142540, #142949)',
		17: 'linear-gradient(#14253F, #142642)',
		18: 'linear-gradient(#142238, #201F32)',
		19: 'linear-gradient(#182136, #281F30)',
		20: 'linear-gradient(#192134, #301A29)',
		21: 'linear-gradient(#142034, #201827)',
		22: 'linear-gradient(#021225, #151420)',
		23: 'linear-gradient(#001220, #100000)'
	};
	const textColours = {
		0:  '#777777',
		1:  '#777777',
		2:  '#7B7B7B',
		3:  '#888888',
		4:  '#999999',
		5:  '#AAAAAA',
		6:  '#BBBBBB',
		7:  '#CCCCCC',
		8:  '#DDDDDD',
		9:  '#EEEEEE',
		10: '#EEEEEE',
		11: '#EEEEEE',
		12: '#EEEEEE',
		13: '#EEEEEE',
		14: '#EEEEEE',
		15: '#EEEEEE',
		16: '#DDDDDD',
		17: '#CCCCCC',
		18: '#BBBBBB',
		19: '#AAAAAA',
		20: '#999999',
		21: '#888888',
		22: '#7A7A7A',
		23: '#777777'
	};
	const iconColours = {
		0:  0.4,
		1:  0.5,
		2:  0.5,
		3:  0.5,
		4:  0.6,
		5:  0.6,
		6:  0.7,
		7:  0.8,
		8:  0.9,
		9:  1,
		10: 1,
		11: 1,
		12: 1,
		13: 1,
		14: 1,
		15: 1,
		16: 1,
		17: 1,
		18: 0.9,
		19: 0.8,
		20: 0.7,
		21: 0.6,
		22: 0.6,
		23: 0.5
	};
	debug.log('THEMEMANAGER', 'Updating background colour for hour', hour);
	set_css_variable('--text-colour', textColours[hour]);
	set_css_variable('--main-bg-image', hourColours[hour]);
	set_css_variable('--vl-opacity', iconColours[hour]);
}

themeManager.init = function() {
	themeManager.vl_update();
	sleepHelper.addWakeHelper(themeManager.vl_update);
}

themeManager.teardown = function() {
	clearTimeout(themeManager.vl_timer);
	sleepHelper.removeWakeHelper(themeManager.vl_update);
	unset_css_variable('--text-colour');
	unset_css_variable('--main-bg-image');
	unset_css_variable('--vl-opacity');
}