/*
 * This adapter function connects Inbenta's chatbot solution with InContact
 * InContact documentation: https://developer.niceincontact.com/API/PatronAPI
 *
 * @param {Object} incontactConf [InContact APP configuration]
 * @param {Object} sdkConfig [SDK configuration]
 */
var inbentaIncontactAdapter = function (incontactConf, sdkConfig) {

    let workingTime = true;
    let agentActive = false;
    let showNoAgentsAvailable = false;
    let inbentaChatbotSession = '';
    let getMessageTimeout = 24; // Second to await for a new message
    let errorResponse = 0;
    let oneRequestTranscript = incontactConf.oneRequestTranscript == undefined ? true : incontactConf.oneRequestTranscript;

    if (!incontactConf.enabled) {
        return function () { };
    } else if (!incontactConf.payload.pointOfContact) {
        console.warn('InContact adapter is misconfigured, therefore it has been disabled.');
        console.warn('Make sure pointOfContact is configured.');
    }
    if (sdkConfig == null) {
        sdkConfig = {
            lang: 'en',
            labels: {
                en: {
                    'transcriptConversationTitle': 'Transcript Conversation',
                    'disconnectionError': 'Oops! The agent got disconnected. Please try connecting again if your query is still unanswered',
                    'outOfTimeMessage': 'There are no agents available at this moment',
                    'operationClosed': 'The operation for today is CLOSED'
                }
            }
        }
    }

    // Initialize inContact session on/off variable
    var incontactSessionOn;
    if (typeof incontactConf.outOfTimeDetection === "undefined") {
        incontactConf.outOfTimeDetection = "department is currently closed";
        console.warn('The variable "incontactConf.outOfTimeDetection" is not defined, getting the default value.');
    }

    /*
     * InContact session cookies management function
     */
    var IncontactSession = {
        get: function (key) {
            return localStorage.getItem(key);
        },
        set: function (key, value) {
            localStorage.setItem(key, value);
        },
        delete: function (key) {
            localStorage.removeItem(key);
        }
    };

    var fromName = IncontactSession.get('incontactUserName');
    if (typeof fromName !== "undefined") {
        incontactConf.payload.fromName = fromName;
    }

    // Debug function
    function dd(message = '', color = 'background: #fff; color: #000') {

        if (typeof message === 'object') {
            message = JSON.stringify(message);
        }

        if (incontactConf.debugMode && message) console.log('%c ' + message, color);
    }

    // Bulk remove InContact session cookies
    function removeIncontactCookies(cookies) {
        if (typeof cookies === 'string') {
            IncontactSession.delete(cookies);
        } else if (Array.isArray(cookies)) {
            cookies.forEach(function (cookie) {
                IncontactSession.delete(cookie);
            });
        }
    }

    return function (chatbot) {
        window.chatbotHelper = chatbot;
        // Initialize inContact auth object
        var auth = {
            chatSessionId: '',
            isManagerConnected: false,
            closedOnTimeout: true,
            noResults: 1,
            firstQuestion: '',
            timers: {
                getChatText: 0
            },
            activeChat: true
        };

        /*
         * Conect to InContact function (triggered onStartEscalation)
         */
        var connectToIncontact = function () {
            incontactSessionOn = true;
            startChat();
            auth.closedOnTimeout = false;
            // Start "no agents" timeout
            auth.timers.noAgents = setTimeout(function () {
                if (!auth.isManagerConnected) {
                    showNoAgentsAvailable = true;
                    incontactSessionOn = false;
                    endChatSession();
                    chatbot.actions.displaySystemMessage({ message: 'no-agents', translate: true });
                    chatbot.actions.sendMessage({ directCall: "escalationNoAgentsAvailable" });
                }
            }, incontactConf.agentWaitTimeout * 1000);
        };

        function startChat() {
            // Get inContact chat profile info
            getChatProfile();
            errorResponse = 0;
            // Create inContact chat room
            makeChat(function (resp) {
                workingTime = true;
                auth.chatSessionId = resp.chatSessionId;
                IncontactSession.set('inbentaIncontactActive', 'active');
                IncontactSession.set('incontactChatSessionId', auth.chatSessionId);
                getChatText();
            });
        }

        /*
         * Get inContact chat profile info [request]
         */
        var getChatProfile = function () {
            var options = {
                type: 'GET',
                url: incontactConf.middlewareUrl + '/chat-profile?pointOfContact=' + incontactConf.payload.pointOfContact,
                async: true
            };
            var request = requestCall(options);
            request.onload = request.onerror = function () {
                if (!this.response) {
                    return;
                }
                var resp = (this.response && this.response.indexOf('<!DOCTYPE') == -1) ? JSON.parse(this.response) : {};
                if (!resp.chatProfile) {
                    return;
                }
                if (incontactConf.agent.avatarImage === '') {
                    for (var chatId in resp.chatProfile) {
                        if (resp.chatProfile.hasOwnProperty(chatId) && resp.chatProfile[chatId].heroImage) {
                            incontactConf.agent.avatarImage = resp.chatProfile[chatId].heroImage;
                            break;
                        }
                    }
                }
            };
        };

        /*
         * Create inContact chat room [request]
         */
        var makeChat = function (callback) {
            var options = {
                type: 'POST',
                url: incontactConf.middlewareUrl + '/make-chat',
                async: true,
                data: JSON.stringify(incontactConf.payload)
            };
            requestCall(options, callback);
        };

        /*
         * Get inContact agent responses [request]
         */
        var getChatText = function () {

            dd("workingTime: " + workingTime);
            if (!workingTime) return;

            clearTimeout(auth.timers.getChatText);
            var options = {
                type: 'GET',
                url: incontactConf.middlewareUrl + '/get-response?timeout=' + getMessageTimeout + '&chatSessionId=' + auth.chatSessionId,
                async: true
            };
            var request = requestCall(options);
            request.onload = function () {
                errorResponse = 0;
                if (!this.response) {
                    return;
                }
                var resp = (this.response) ? JSON.parse(this.response) : {};
                if (resp.chatSession) auth.chatSessionId = resp.chatSession;
                if (workingTime && auth.activeChat) IncontactSession.set('incontactChatSessionId', auth.chatSessionId);
                if (resp.error !== undefined) return;
                if (resp.messages === undefined || resp.messages === null) return;
                resp.messages.forEach(function (message) {
                    if (typeof message.Type !== 'undefined' && typeof message.Status !== 'undefined' && message.Status === 'Waiting') {
                        // in waiting we send chat, to connect with incontact
                        retrieveLastMessages();
                    } else if (typeof message.Type !== 'undefined' && typeof message.Status !== 'undefined' && message.Status === 'Active') {
                        dd("agent Joined");
                        clearTimeout(auth.timers.noAgents);
                        auth.isManagerConnected = true;
                        chatbot.actions.displaySystemMessage({
                            message: 'agent-joined', // Message can be customized in SDKconf -> labels
                            replacements: { agentName: incontactConf.agent.name },
                            translate: true
                        });
                        chatbot.actions.hideChatbotActivity();
                        chatbot.actions.enableInput();
                        if (auth.firstQuestion) {
                            chatbot.actions.displayChatbotMessage({ type: 'answer', message: auth.firstQuestion });
                            auth.firstQuestion = '';
                        }
                    } else if (typeof message.Type !== 'undefined' && typeof message.Status !== 'undefined' && message.Status === 'Disconnected') {
                        clearTimeout(auth.timers.getChatText);
                    }

                    if (typeof message.Text !== 'undefined' && typeof message.PartyTypeValue !== 'undefined') {
                        switch (message.PartyTypeValue) {
                            case '1':
                            case 'Agent':
                                chatbot.actions.hideChatbotActivity();
                                chatbot.actions.displayChatbotMessage({ type: 'answer', message: message.Text });
                                break;
                            case 'System':
                                if (message.Type === 'Ask') {
                                    if (message.Text !== 'Hello, what is your name?') {
                                        auth.firstQuestion = message.Text;
                                    }
                                } else if (message.Text === '$Localized:ChatSessionEnded') {
                                    clearTimeout(auth.timers.getChatText);
                                    agentLeft()
                                }
                        }
                    } else if (typeof message.PartyTypeValue !== 'undefined' && typeof message.Type !== 'undefined' && message.Type === 'AgentTyping') {
                        if (message.IsTextEntered === 'True' || message.IsTyping === 'True') {
                            chatbot.actions.displayChatbotActivity();
                        } else {
                            chatbot.actions.hideChatbotActivity();
                        }
                    }
                });
            };

            request.onerror = function () {
                if (!this.response) {
                    validateResponseError();
                    return;
                }
            };
        };

        /*
         * Send a single message to Incontact [request]
         */
        var sendMessageToIncontact = function (message, author, async, callback, callbackData) {
            if (auth.chatSessionId === '') return;

            async = typeof async === 'boolean' ? async : false;

            var options = {
                type: 'POST',
                url: incontactConf.middlewareUrl + '/send-text?chatSessionId=' + auth.chatSessionId,
                async: async,
                data: JSON.stringify({
                    'label': (author === 'undefined') ? incontactConf.defaultUserName : author,
                    'message': message
                })
            };
            requestCall(options, callback, callbackData);
        };

        /*
         * Send multiple message to Incontact [request] (recursive, ordered)
         */
        var sendMultipleMessagesToIncontact = function (messageArray) {

            dd("--- sendMultipleMessagesToIncontact ---");
            dd(messageArray);
            if (messageArray.length > 0) {
                if (oneRequestTranscript) {
                    var transcriptConversationTitle = sdkConfig.labels[sdkConfig.lang].transcriptConversationTitle ?? 'Transcript';
                    var options = {
                        type: 'POST',
                        url: incontactConf.middlewareUrl + '/send-text?chatSessionId=' + auth.chatSessionId,
                        async: false,
                        data: JSON.stringify({
                            'messages': messageArray,
                            'assistant': incontactConf.defaultChatbotName,
                            'guest': incontactConf.payload.fromName ? incontactConf.payload.fromName : incontactConf.defaultUserName,
                            'system': incontactConf.defaultSystemName,
                            'transcriptConversationText': transcriptConversationTitle
                        })
                    };
                    requestCall(options);
                    return;
                }

                var messageObj = messageArray[0];
                var author = '';
                switch (messageObj.user) {
                    case 'assistant':
                        author = incontactConf.defaultChatbotName;
                        break;
                    case 'guest':
                        author = incontactConf.payload.fromName ? incontactConf.payload.fromName : incontactConf.defaultUserName;
                        break;
                    case 'system':
                    default:
                        author = incontactConf.defaultSystemName;
                }

                messageArray.shift();
                if (workingTime) {
                    sendMessageToIncontact(messageObj.message, author, false, sendMultipleMessagesToIncontact, messageArray);
                }
            }
        };

        /*
         * Close InContact chat session [request]
         */
        var endChatSession = function () {
            dd("---endChatSession---");
            dd("auth.chatSessionId: " + auth.chatSessionId);
            dd("workingTime: " + workingTime);

            if (auth.chatSessionId === '' || !workingTime) return;

            var options = {
                type: 'POST',
                url: incontactConf.middlewareUrl + '/end-chat?chatSessionId=' + auth.chatSessionId
            };
            auth.activeChat = false;
            requestCall(options, finishChat);
        };

        var finishChat = function () {
            auth.chatSessionId = '';
            auth.isManagerConnected = false;
            incontactSessionOn = false;
            auth.closedOnTimeout = true;
            agentActive = false;
            errorResponse = 0;
            clearTimeout(auth.timers.noAgents);
            clearTimeout(auth.timers.getChatText);
            removeIncontactCookies(['inbentaIncontactActive', 'incontactAccessToken', 'incontactResourceBaseUrl', 'incontactChatSessionId', 'inbentaChatbotSession']);
            chatbot.actions.hideChatbotActivity();
            if (!showNoAgentsAvailable) {
                enterQuestion();
            }
            showNoAgentsAvailable = false;
            chatbot.actions.enableInput();
        }

        /*
        * InContact http [request] template
        */
        var requestCall = function (requestOptions, callback, callbackData) {
            var xmlhttp = new XMLHttpRequest();
            requestOptions.async = true;
            if (!requestOptions.headers) requestOptions.headers = {};
            if (!requestOptions.headers['X-Inbenta-Token']) {
                requestOptions.headers['X-Inbenta-Token'] = inbentaChatbotSession;
            }
            if (requestOptions.headers['Content-Type'] === undefined) {
                requestOptions.headers['Content-Type'] = 'application/json';
            }

            xmlhttp.onreadystatechange = function () {
                if (xmlhttp.readyState === XMLHttpRequest.DONE) {
                    var response = (this.responseText && this.responseText.indexOf('<!DOCTYPE') == -1) ? JSON.parse(this.responseText) : { messages: [] };
                    dd("Request completed:" + " " + requestOptions.url, 'background: #222; color: #BA55BA');
                    dd(response);

                    var handle = httpResponseHandler(requestOptions.url, response.messages);
                    if (typeof handle[xmlhttp.status] === 'function') {
                        handle[xmlhttp.status]();
                    }

                    if (callback) {
                        if (callbackData) {
                            callback(callbackData);
                        } else {
                            callback(xmlhttp.response ? JSON.parse(xmlhttp.response) : {});
                        }
                    }
                }
            };

            xmlhttp.open(requestOptions.type, requestOptions.url, requestOptions.async);

            for (var key in requestOptions.headers) {
                if (requestOptions.headers.hasOwnProperty(key)) {
                    xmlhttp.setRequestHeader(key, requestOptions.headers[key]);
                }
            }
            xmlhttp.send(requestOptions.data);

            return xmlhttp;
        };

        /*
         * InContact http response handler
         */
        var httpResponseHandler = function (url, messages) {
            var httpCodeErrors = {
                200: function () {
                    if (messages && messages.length > 0) {
                        messages.forEach(function (message) {
                            var text = message.Text;
                            if (text && text.includes(incontactConf.outOfTimeDetection)) return outOfTime(text);
                        });
                    }
                    if (!auth.closedOnTimeout) auth.timers.getChatText = setTimeout(getChatText, getMessageTimeout);
                },
                202: {},
                204: function () {
                    if (!auth.closedOnTimeout) auth.timers.getChatText = setTimeout(getChatText, getMessageTimeout);
                },
                400: genericError,
                401: genericError,
                404: agentLeft,
                500: genericError
            };
            switch (url) {
                case incontactConf.middlewareUrl + '/make-chat':
                case incontactConf.middlewareUrl + '/get-response?timeout=' + getMessageTimeout + '&chatSessionId=' + auth.chatSessionId:
                case incontactConf.middlewareUrl + '/send-text?chatSessionId=' + auth.chatSessionId:
                    return httpCodeErrors;
                default:
                    return {};
            }
        };

        /*
         * Display a chatbot "Enter your question" message (after inContact session is closed, manually or on error)
         * Message can be customized in SDKconf -> labels
         */
        function outOfTime(text) {
            endChatSession();
            chatbot.actions.displayChatbotMessage({
                type: 'answer',
                message: text,
            });
            workingTime = false;
            return {};
        }

        /**
         * If the response checker has an error, validate if execute again the request o ends chat
         */
        function validateResponseError() {
            if (incontactSessionOn) {
                errorResponse++;
                if (!auth.closedOnTimeout && errorResponse == 1) {
                    auth.timers.getChatText = setTimeout(getChatText, getMessageTimeout);
                } else {
                    chatbot.actions.displaySystemMessage({ message: sdkConfig.labels[sdkConfig.lang].disconnectionError, translate: false });
                    endChatSession();
                }
            }
        }

        /*
         * Generic message on unexpected inContact session error
         * Message can be customized in SDKconf -> labels
         */
        function genericError() {
            return chatbot.actions.displaySystemMessage({
                translate: true,
                message: 'alert-title',
                id: 'incontact-error',
                options: [{
                    label: 'alert-button',
                    value: 'try-again'
                }]
            });
        }

        /*
         * Display a chatbot "Enter your question" message (after inContat session is closed, manually or on error)
         * Message can be customized in SDKconf -> labels
         */
        function enterQuestion() {
            return chatbot.actions.displayChatbotMessage({
                type: 'answer',
                message: 'enter-question',
                translate: true
            });
        }

        /*
         * Close inContact session, remove InContact cookies, diplay an "Agent left" message, set default chatbotIcon
         * Message can be customized in SDKconf -> labels
         */
        function agentLeft() {
            incontactSessionOn = false;
            auth.activeChat = false;
            chatbot.actions.setChatbotIcon({ source: 'default' });
            chatbot.actions.setChatbotName({ source: 'default' });
            agentActive = false;
            if (workingTime) {
                chatbot.actions.displaySystemMessage({
                    message: 'agent-left',
                    replacements: { agentName: incontactConf.agent.name },
                    translate: true
                });
                finishChat();
            }
        }

        /**
         * Validate the operation hours
         */
        function lookForOperationHours() {
            var options = {
                type: 'GET',
                url: incontactConf.middlewareUrl + '/hours-of-operation' + (incontactConf.profileIdHoursOperation > 0 ? '?profileIdHoursOperation=' + incontactConf.profileIdHoursOperation : ''),
                async: true,
                data: {}
            };
            requestCall(options, function (response) {
                var validHours = true;
                if (response.resultSet !== undefined && response.resultSet.hoursOfOperationProfiles !== undefined && response.resultSet.hoursOfOperationProfiles[0] !== undefined) {
                    var currentD = new Date();
                    var weekday = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
                    var closed = false;
                    var outOfTime = false;
                    var outOfTimeMessage = '';
                    var days = null;
                    for (var i = 0; i < response.resultSet.hoursOfOperationProfiles.length; i++) {
                        days = response.resultSet.hoursOfOperationProfiles[i].days;
                        Object.keys(days).forEach(key => {
                            if (weekday[currentD.getDay()] === days[key].day) {
                                if (days[key].isClosedAllDay === 'True') {
                                    closed = true;
                                    return false;
                                }
                                var start = days[key].openTime.split(':');
                                var end = days[key].closeTime.split(':');
                                var opStart = new Date();
                                var opEnd = new Date();
                                opStart.setHours(start[0], start[1], start[2]);
                                opEnd.setHours(end[0], end[1], end[2]);

                                var additionlTime = false;
                                var additionlTimeText = '';
                                if (days[key].additionalOpenTime !== '' && days[key].additionalCloseTime !== '') {
                                    var startAdditional = days[key].additionalOpenTime.split(':');
                                    var endAdditional = days[key].additionalCloseTime.split(':');
                                    var opStartAdditional = new Date();
                                    var opEndAdditional = new Date();
                                    opStartAdditional.setHours(startAdditional[0], startAdditional[1], startAdditional[2]);
                                    opEndAdditional.setHours(endAdditional[0], endAdditional[1], endAdditional[2]);
                                    if (currentD >= opStartAdditional && currentD < opEndAdditional) {
                                        additionlTime = true;
                                    }
                                    additionlTimeText = ' and from ' + days[key].additionalOpenTime.substring(0, 5) + ' to ' + days[key].additionalCloseTime.substring(0, 5);
                                }
                                if ((currentD >= opStart && currentD < opEnd) || additionlTime) {
                                    validHours = true;
                                    closed = false;
                                    outOfTime = false;
                                    i = response.resultSet.hoursOfOperationProfiles.length;
                                    return false;
                                }
                                outOfTimeMessage = sdkConfig.labels[sdkConfig.lang].outOfTimeMessage;
                                outOfTime = true;
                                return false;
                            }
                        });
                    }
                    if (closed) {
                        validHours = false;
                        chatbot.actions.displaySystemMessage({ message: sdkConfig.labels[sdkConfig.lang].operationClosed, translate: false });
                        finishChat();
                        return false;
                    }
                    if (outOfTime) {
                        validHours = false;
                        chatbot.actions.displaySystemMessage({ message: outOfTimeMessage, translate: false });
                        finishChat();
                        return false;
                    }
                }
                if (validHours) {
                    lookForActiveAgents();
                }
            });
        }

        /**
         * Search if there are agents available
         */
        function lookForActiveAgents() {
            var queryString = 'fields=agentStateId,isActive,agentStateName,firstName,lastName,teamId,agentId,skillId&top=200&teamId=' + incontactConf.teamId
            var options = {
                type: 'GET',
                url: incontactConf.middlewareUrl + '/agents-availability?' + queryString,
                async: true,
                data: {}
            };
            requestCall(options, function (response) {
                agentActive = false;
                if (response.agentStates !== undefined && response.agentStates !== null) {
                    Object.keys(response.agentStates).forEach(key => {
                        if ((incontactConf.teamId == response.agentStates[key].teamId || incontactConf.teamId == 0) &&
                            response.agentStates[key].agentStateId === 1 && response.agentStates[key].agentStateName === 'Available'
                        ) {
                            //console.log(response.agentStates[key]);
                            agentActive = true;
                            return false;
                        }
                    });
                    if (agentActive) {
                        continueWithEscalation();
                    } else {
                        chatbot.actions.displaySystemMessage({ message: 'no-agents', translate: true });
                        showNoAgentsAvailable = true;
                        finishChat();
                        chatbot.actions.sendMessage({ directCall: "escalationNoAgentsAvailable" });
                    }
                } else { //Continue with escalation if we can't validate the availability 
                    continueWithEscalation();
                }
            });
        }

        /**
         * Continue executig the escalation
         */
        function continueWithEscalation() {
            agentActive = true;
            var messageData = {
                directCall: 'escalationStart',
            }
            chatbot.actions.sendMessage(messageData);
        }

        /*
         * Get chatbot conversation mesages and prepare them to be sent to InContact agent
         */
        var retrieveLastMessages = function () {
            var transcript = chatbot.actions.getConversationTranscript();
            sendMultipleMessagesToIncontact(transcript);
            auth.timers.getChatText = setTimeout(getChatText, getMessageTimeout);
        };

        /*
         * If one of the fields is assigned to the label 'EMAIL_ADDRESS', the value provided by the user will replace the 'fromAdress' field.
         * The rest of the escalation data will be inserted in the 'parameters' array.
         */
        var updateChatInfo = function (escalateData) {
            incontactConf.payload.parameters = [];
            for (var field in escalateData) {
                var fieldName = field.toLowerCase();
                if (fieldName == 'email_address') {
                    incontactConf.payload.fromAddress = escalateData[field];
                } else if (fieldName == 'first_name') {
                    incontactConf.payload.fromName = escalateData[field];
                    IncontactSession.set('incontactUserName', escalateData[field]);
                }
                incontactConf.payload.parameters.push(escalateData[field]);
            }

            dd(incontactConf.payload);
        };

        /*
         *
         * CHATBOT SUBSCIPTIONS
         *
         */

        // Initiate escalation to inContact
        chatbot.subscriptions.onEscalateToAgent(function (escalateData, next) {
            dd("---onEscalationStart--- payload:");
            //Update chat payload before creating the chat
            updateChatInfo(escalateData);
            chatbot.actions.displaySystemMessage({ message: 'wait-for-agent', translate: true }); // Message can be customized in SDKconf -> labels
            chatbot.actions.displayChatbotActivity();
            chatbot.actions.disableInput();
            //Creation fo the chat
            connectToIncontact();
        });

        // Route messages to inContact
        chatbot.subscriptions.onSendMessage(function (messageData, next) {
            dd("---onSendMessage---:");
            dd(messageData);
            dd("---incontactSessionOn---: " + incontactSessionOn);
            if (incontactSessionOn) {
                sendMessageToIncontact(messageData.message, incontactConf.payload.fromName, true);
            } else {
                if (!agentActive &&
                    (
                        (messageData.directCall !== undefined && messageData.directCall === 'escalationStart') ||
                        (escalationOffer && messageData.userActivityOptions !== undefined && messageData.userActivityOptions === 'yes')
                    )
                ) {
                    escalationOffer = false;
                    chatbot.actions.disableInput();
                    chatbot.actions.displayChatbotActivity();

                    const chatBotmessageData = {
                        type: 'answer',
                        message: '<em>Looking for agents</em>',
                    }
                    chatbot.actions.displayChatbotMessage(chatBotmessageData);
                    lookForOperationHours();

                    return false;
                }
                escalationOffer = false;

                return next(messageData);
            }
        });

        var agentIconSet = false;
        var escalationOffer = false;

        // Show custom agent's picture
        chatbot.subscriptions.onDisplayChatbotMessage(function (messageData, next) {
            if ((incontactSessionOn && incontactConf.agent && !agentIconSet) || auth.isManagerConnected) {
                if (incontactConf.agent.avatarImage !== '') chatbot.actions.setChatbotIcon({ source: 'url', url: incontactConf.agent.avatarImage });
                if (incontactConf.agent.name !== '') chatbot.actions.setChatbotName({ source: 'name', name: incontactConf.agent.name });
                agentIconSet = true;
            } else {
                //Set the name empty when the chatbot is responding
                chatbot.actions.setChatbotName({ source: 'name', name: ' ' });
            }
            if (messageData.type === "polarQuestion"
                && messageData.attributes !== null
                && messageData.attributes.DIRECT_CALL !== undefined
                && messageData.attributes.DIRECT_CALL === "escalationStart"
            ) {
                //When escalation is offered using polar question
                escalationOffer = true;
            }
            return next(messageData);
        });

        // Finish looking for agents Timeout
        chatbot.subscriptions.onResetSession(function (next) {
            dd("---onResetSession---");
            agentActive = false;
            clearTimeout(auth.timers.noAgents);
            clearTimeout(auth.timers.getChatText);
            return next();
        });

        // Handle inContact session/no-session on refresh
        chatbot.subscriptions.onReady(function (next) {
            dd("---onReady---");
            var sessionData = chatbot.actions.getSessionData();
            if (sessionData) {
                var inbentaChatbotSessionTmp = IncontactSession.get('inbentaChatbotSession');
                inbentaChatbotSession = sessionData.sessionId;
                IncontactSession.set('inbentaChatbotSession', inbentaChatbotSession);
                if (inbentaChatbotSessionTmp !== inbentaChatbotSession) {
                    removeIncontactCookies(['inbentaIncontactActive', 'incontactAccessToken', 'incontactResourceBaseUrl', 'incontactChatSessionId', 'inbentaChatbotSession']);
                }
            }
            var statusChat = IncontactSession.get('inbentaIncontactActive');
            if (statusChat === 'active') {
                auth.chatSessionId = IncontactSession.get('incontactChatSessionId');
                incontactSessionOn = true;
                auth.closedOnTimeout = false;
                getChatProfile();
                auth.timers.getChatText = setTimeout(getChatText, getMessageTimeout);
            }
        });

        chatbot.subscriptions.onSelectSystemMessageOption(function (optionData, next) {
            if (optionData.id === 'exitConversation' && optionData.option.value === 'yes' && incontactSessionOn === true) {
                // Clear inContact chatSession on exitConversation
                clearTimeout(auth.timers.getChatText);
                incontactSessionOn = false;
                auth.closedOnTimeout = true;
                endChatSession();
                chatbot.actions.setChatbotIcon({ source: 'default' });
                chatbot.actions.setChatbotName({ source: 'default' });
                chatbot.actions.displaySystemMessage({
                    message: 'chat-closed', // Message can be customized in SDKconf -> labels
                    translate: true
                });
            } else if (optionData.option.value === 'try-again') {
                // Handle generic error
                enterQuestion();
            } else {
                return next(optionData);
            }
        });

        // DATA KEYS LOG
        chatbot.subscriptions.onDisplaySystemMessage(function (messageData, next) {
            // Contact Attended log on agent join conversation system message
            if (messageData.message === 'agent-joined') {
                chatbot.api.track('CHAT_ATTENDED', { value: 'TRUE' });
            }
            // Contact Unattended log on no agent available system message
            else if (messageData.message === 'no-agents') {
                chatbot.api.track('CHAT_NO_AGENTS', { value: 'TRUE' });
            }
            return next(messageData);
        });

        chatbot.subscriptions.onStartConversation(function (conversationData, next) {
            if (conversationData.sessionId !== undefined && conversationData.sessionId !== '') {
                inbentaChatbotSession = conversationData.sessionId;
                IncontactSession.set('inbentaChatbotSession', inbentaChatbotSession);
            }
        });
    }
}

/**
 *
 * HELPER: Returns Promise resolving to dummy Object { agentsAvailable: true }
 *
 */
var inbentaPromiseAgentsAvailableTrue = function () {
    return new Promise(function (resolve, reject) {
        resolve({ 'agentsAvailable': true });
    });
}