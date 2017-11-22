"use strict";

var wsuri = 'ws://localhost:8080/ws';

var sess;

var connection = null;

function onMessage(args, kwargs, details) {
	var event = args[0];
	console.log("event received", event, details);
}

function connect() {

	// the WAMP connection to the Router
	//
	var connection = new autobahn.Connection({
		url: wsuri,
		realm: "realm1"
	});

	connection.onopen = function (session, details) {

		sess = session;
		console.log("Connected to " + wsuri, details);

		sess.subscribe("api:file:changes", onMessage).then(
			function(subscription) {
				console.log("subscribed", subscription);
			},
			function(error) {
				console.log("subscription error", error);
			}
		);
		console.log("post subscribe");
	};

	connection.onclose = function(reason, details) {
		sess = null;
		console.log("connection closed ", reason, details);
	};

	connection.open();
}

$(document).ready(function()
{
	connect();
});
