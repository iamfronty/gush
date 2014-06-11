var request = require('request'),
	cheerio = require('cheerio'),
	moment = require('moment'),
	url = require('url'),
	zlib = require('zlib');

var r = request.defaults({
	'proxy': 'http://localhost:8443'
});

;(function() {
	var http = require('http'),
		address = '127.0.0.1',
		port = 9001;
		
	var upstream = (function(r) {
		var defaultOptions = {
			headers: {
				'User-Agent': 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.114 Safari/537.36'
			}
		};
		
		function get(url, callback) {
			var options = defaultOptions;
			options.url = url;
			var requestObject = r.get(options);
			handleRequest(requestObject, callback);
		}
		
		function handleRequest(req, callback) {
			req.on('response', function(response) {
				var chunks = [];
				response.on('data', function(chunk) {
					chunks.push(chunk);
				}).on('end', function() {
					var buffer = Buffer.concat(chunks);
					var encoding = response.headers['content-encoding'];
					if(encoding == 'gzip') {
						zlib.gunzip(buffer, function(error, decoded) {
							callback(error, decoded && decoded.toString());
						});
					} else if(encoding == 'deflate') {
						zlib.inflate(buffer, function(error, decoded) {
							callback(error, decoded && decoded.toString());
						});
					} else {
						callback(null, buffer.toString());
					}
				});
			}).on('error', function(error) {
				callback(error);
			});
		}

		return {
			loadUrl: get
		};
		
	})(r);
	
	function start() {
		http.createServer(request).listen(port, address);
		console.log('Server running on ' + address + ':' + port);
	}
	
	function request(request, response) {
		var urlObject = url.parse(request.url, true);
		if(urlObject.query) {
			process(response, urlObject.query);
		} else {
			send(response, {
				code: 404
			});
		}
	}
	
	function process(response, query) {
		upstream.loadUrl('http://thepiratebay.se/search/' + encodeURIComponent(query.search) + '/0/7/0', function(error, data) {
			gotUpstreamResponse(response, error, data);
		});
	}
	
	function gotUpstreamResponse(response, error, data) {
		if(error) {
			console.log(error);
		} else {
			Scraper_Pirate.parse(data, function(results) {
				send(response, {
					code: 200,
					body: results
				});
			});
		}
	}
	
	function send(response, data) {
		var body = '';
		response.writeHead(data.code, {
			'Content-Type': 'text/plain'
		});
		if(data.code === 200) {
			body = JSON.stringify(data.body);
		}
		response.end(body);
	}
	
	start();
	
})();

var Scraper_Pirate = function(cheerio) {

	var responseSet = [];

    function getSearchResults(body, callback) {
		if(body.indexOf('No hits.') == -1) {
			var $ = cheerio.load(body);
			$('#searchResult tr').each(function() {
				parseRow(this, $);
			});
		}
		callback(responseSet);
    }
    
	function parseRow(row, $) {
		var result = $(row);
		if (!result.hasClass('header')) {
			var link = result.find('.detName a');
			var name = link.html();
			var magnet = result.find("a[href^='magnet']").attr('href');

			var commentAlt = result.find('img[alt*="comment"]').attr('alt');
			if (commentAlt) {
				var comments = commentAlt.replace(/\D/g, '');
			} else {
				var comments = '0';
			}
			var dataArea = result.find('font.detDesc').html();
			if (!dataArea) return true;
			var data = dataArea.split(',');
			
			var size = data[1].replace(' Size ', '').replace('&nbsp;', ' ');
			var date = data[0].replace('Uploaded ', '').split('&nbsp;');
			var recordDate = moment().unix();
			if (date[1].indexOf('mins') != -1) {
				var prevTime = date[0].replace(/\D/g, '');
				var d = new Date();
				d.setMinutes(d.getMinutes() - prevTime);
				var recordDate = moment(d).unix();
			} else if (date[1].indexOf(':') == -1) {
				// full date supplied
				var monthParts = date[0].split('-');
				recordDate = moment(new Date(date[1], monthParts[0], monthParts[1], 15, 0, 0, 0)).unix();
			} else {
				var timeParts = date[1].split(':');
				if (date[0] == 'Y-day') {
					var d = new Date();
					d.setDate(d.getDate() - 1);
					d.setHours(timeParts[0]);
					d.setMinutes(timeParts[1]);
					recordDate = moment(d).unix();
				} else if(date[0] == 'Today') {
					var d = new Date();
					d.setHours(timeParts[0]);
					d.setMinutes(timeParts[1]);
					recordDate = moment(d).unix();
				} else {
					recordDate = moment().unix();
				}
			}
			if(!recordDate) recordDate = moment().unix();
			responseSet.push({
				'name': name,
				'magnet': magnet,
				'comments': comments,
				'seeds': result.find('td').eq(2).text(),
				'peers': result.find('td').eq(3).text(),
				'id': link.attr('href').split('/')[2],
				'size': size,
				'date': recordDate
			});
		}
	}

	return {
		parse: getSearchResults
	};

} (cheerio);