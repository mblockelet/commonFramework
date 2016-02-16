(function() {
'use strict';

var objectHasProperties = function(object) {
   for (var iProperty in object) {
      return true;
   }
   return false;
};

var syncScope;

// TODO: clean through instanciation with options
if (typeof window.rootUrl === 'undefined') {
   window.rootUrl = 'commonFramework/';
}

function logError(details) {
   $.post(rootUrl + "sync/jsErrors.php", {"details": details},
      function(data, textStatus) {
      }
   ); 
}

window.onerror = function(message, file, line) {
  logError(file + ':' + line + '\n\n' + message);
};

// TODO: this doesn't work anymore with angular > 1.3, provide a real module
window.SyncCtrl = function($scope, $timeout) {
   $scope.syncStatus = function() {
      var params = [];
      params[SyncQueue.statusIdle] = { strStatus: "I", color: "green"};
      params[SyncQueue.statusWillSend] = { strStatus: "W", color: "green"};
      params[SyncQueue.statusSending] = { strStatus: "S", color: "blue"};
      params[SyncQueue.statusSendingWillSend] = { strStatus: "S", color: "blue"};

      var statusParams = params[SyncQueue.status];
      if (SyncQueue.nbFailures > 0) {
         statusParams.strStatus = SyncQueue.nbFailures;
         statusParams.color = "red";
      }
      return statusParams;
   };

   $scope.now = ModelsManager.now;

   $scope.ok = function() {
      $scope.showDetails = false;
   };

   $scope.ping = function() {
      $scope.pingStatus = "En cours";
      $scope.lastPingStart = ModelsManager.now();
      $scope.lastPingEnd = $scope.lastPingStart;
      $.get(rootUrl + "sync/ping.php",
          function() {
             $scope.pingStatus = "OK";
             $scope.lastPingEnd = $scope.now();
             $scope.$apply();
          }
      ).fail(function() {
         $scope.pingStatus = "Error";
         $scope.lastPingEnd = $scope.now();
         $scope.$apply();
      });
   };

   $scope.show = function() {
      $scope.showDetails = true;
      $scope.watchIsOnline = false;//watchIsOnline;
      $scope.watchWasOnline = false;//watchWasOnline;
      $scope.ping();
   };
   $scope.testSoundDelayed = function() {
      setTimeout(function() {
         Events.playSound(true);
      }, 100);
      $scope.nbSoundTests++;
   };
   $scope.testSound = function() {
         Events.playSound(true);
         $scope.nbSoundTests++;
   };

   $scope.lastPingStart = null;
   $scope.lastPingEnd = null;
   $scope.pingStatus = "";
   $scope.showDetails = false;
   $scope.syncQueue = SyncQueue;
   $scope.nbSoundTests = 0;
   $scope.lastExecTime = "";
   syncScope = $scope;
   $scope.$on("syncStatusChange", function() {
      $timeout(function(){
          //any code in here will automatically have an apply run afterwards
      });
      //$scope.$apply()
    });
}

window.SyncQueue = {
   modelsManager: null,
   status: 0,
   statusIdle: 0,
   statusWillSend: 1,
   statusSending: 2,
   statusSendingWillSend: 3,
   nbFailures: 0,
   sentVersion: 0,
   serverVersion: 0,
   isApplyingChanges: false,
   requests: {},
   requestSets: {},
   params: {},
   syncStartListeners: {},
   syncEndListeners: {},
   futureSyncEndListeners: {},
   dateLastSync: null,
   dateLastSyncAttempt: null,
   nbSyncs: 0,
   nbSyncsWithoutErrors: 0,
   nbSyncAborted: 0,
   nbFailuresTotal: 0,
   nbFailuresByType: [0, 0, 0, 0],
   callbacks: [],
   laterCallbacks: [],
   nbExceptions: 0,
   numLastAttempt: 0,
   hasSyncedFully: false,

   actionInsert: 1,
   actionUpdate: 2,
   actionDelete: 3,

   objectsToSync: {},
   showAlert: function(message) {
      alert(message);
   },

   setStatus: function(status) {
      this.status = status;
      if (syncScope != null) {
         syncScope.$broadcast("syncStatusChange");
      }
   },

   init: function(modelsManager) {
      this.modelsManager = modelsManager;
      for (var modelName in this.modelsManager.models) {
         this.objectsToSync[modelName] = {};
      }
      this.initErrorHandler();
   },

   setShowAlert: function(showAlert) {
      this.showAlert = showAlert;
   },

   addSyncStartListeners: function(name, listener) {
      this.syncStartListeners[name] = listener;
   },

   addSyncEndListeners: function(name, listener, safe) {
      if (safe) {
         this.futureSyncEndListeners[name] = listener;
      } else {
         this.syncEndListeners[name] = listener;
      }
   },

   removeSyncEndListeners: function(name) {
      if (this.syncEndListeners[name]) {
         delete this.syncEndListeners[name];
      }
      if (this.futureSyncEndListeners[name]) {
         delete this.futureSyncEndListeners[name];
      }
   },

   removeSyncStartListeners: function(name) {
      if (this.syncStartListeners[name]) {
         delete this.syncStartListeners[name];
      }
   },

   callSyncStartListeners: function(data) {
      for (var name in this.syncStartListeners) {
         this.syncStartListeners[name](data);
      }
   },

   callSyncEndListeners: function(data) {
      for (var name in this.syncEndListeners) {
         this.syncEndListeners[name](data);
      }
   },

   markStatusArray: function(data, status) {
      for (var objectID in data) {
         data[objectID].status = status;
      }
   },

   markStatus: function(status) {
      for (var modelName in ModelsManager) {
         this.markStatusArray(this.objectsToSync[modelName], status);
      }
   },

   clearStatusArray: function(data) {
      var newData = {};
      for (var objectID in data) {
         if (data[objectID].status !== this.statusSending) {
            newData[objectID] = data[objectID];
         }
      }
      return newData;
   },

   clearStatus: function() {
      for (var modelName in this.objectsToSync) {
         this.objectsToSync[modelName] = this.clearStatusArray(this.objectsToSync[modelName]);
      }
   },

   addObject: function(modelName, sentObject, action, delaySend) {
      if (this.isApplyingChanges) {
         return;
      }
      var modelObjectsToSync = this.objectsToSync[modelName];
      var primaryKey = ModelsManager.getPrimaryKey(modelName);
      var objectToSync;
      if (modelObjectsToSync[sentObject[primaryKey]] === undefined) {
         objectToSync = {action: action, status: this.statusWillSend};
      } else {
         objectToSync = modelObjectsToSync[sentObject[primaryKey]];
         if (action == this.actionInsert) {
            console.error(sentObject);
            console.error("Error: object inserted twice!");
            return;
         }
         switch(objectToSync.action) {
            case this.actionInsert:
               if (action == this.actionDelete) {
                  if (objectToSync.status < this.statusSending) {
                     delete modelObjectsToSync[sentObject[primaryKey]];
                     return;
                     // deleted before it was ever synced
                  }
                  objectToSync.action = action;
               } else {
                  // do nothing now, we'll switch it to update after sync if in statusSendingWillSend
               }
               break;
            case this.actionUpdate:
               if (action == this.actionDelete) {
                  objectToSync.action = action;
               } else {
                  // do nothing now, we'll switch it to update after sync if in statusSendingWillSend
               }
               break;
            case this.actionDelete:
               alert("Error: action on object after it was deleted!");
               return;
               break;
         }
      }
      if (objectToSync.status === this.statusSending) {
         objectToSync.status = this.statusSendingWillSend;
      }
      modelObjectsToSync[sentObject[primaryKey]] = objectToSync;
      if (!delaySend) {
         this.planToSend();
      }
   },

   insert: function(modelName, object) {
      return this.addObject(modelName, object, this.actionInsert);
   },

   deleteRow: function(modelName, object) {
      return this.addObject(modelName, object, this.actionDelete);
   },

   update: function(modelName, object) {
      return this.addObject(modelName, object, this.actionUpdate);
   },

   planToSend: function(delay, callback) {
      SyncQueue.syncCheckActive();
      if (SyncQueue.status === SyncQueue.statusWillSend) {
         if (callback != undefined) {
            SyncQueue.callbacks.push(callback);
         }
         return;
      }
      if (SyncQueue.status === SyncQueue.statusSending) {
         SyncQueue.setStatus(SyncQueue.statusSendingWillSend);
      }
      if (SyncQueue.status === SyncQueue.statusSendingWillSend) {
         if (callback != undefined) {
            SyncQueue.laterCallbacks.push(callback);
         }
         return;
      }
      SyncQueue.setStatus(SyncQueue.statusWillSend);
      if (delay == undefined) {
         delay = 1000;
      }
      if (delay == 0) {
         SyncQueue.sync(callback);
      } else {
         setTimeout(function() {
            SyncQueue.sync(callback);
         }, delay);
      }
   },

   updateRequestsVersions: function() {
      for (var iRequestInstance = 0; iRequestInstance < SyncQueue.requestInstancesToSend.length; iRequestInstance++) {
         var requestInstance = SyncQueue.requestInstancesToSend[iRequestInstance];
         requestInstance.minVersion = SyncQueue.serverVersion;
      }
   },

   initRequestsVersions: function() {
      SyncQueue.requestInstancesToSend = [];
      for (var requestName in SyncQueue.requests) {
         var request = SyncQueue.requests[requestName];
         for (var instanceID in request) {
            if (typeof request[instanceID] === 'object') {
               if (request[instanceID].resetMinVersion) {
                  request[instanceID].minVersion = 0;
                  delete request[instanceID].resetMinVersion;
               }
               SyncQueue.requestInstancesToSend.push(request[instanceID]);
            }
         }
      }
   },

   initErrorHandler: function() {
      // TODO: call on document for jquery 1.8+
      $(document).ajaxError(function(e, jqxhr, settings, exception) {
        if (settings.url == rootUrl + "sync/syncServer.php") {
            SyncQueue.syncFailed("ajaxError", false, 3);
        }
      });
   },

   syncCheckActive: function() {
      var now = ModelsManager.now();
      if (((SyncQueue.status == SyncQueue.statusSending) || (SyncQueue.status == SyncQueue.statusSendingWillSend) /*|| (SyncQueue.status == SyncQueue.statusWillSend)*/) &&
         SyncQueue.dateLastSyncAttempt && (now.getTime() - SyncQueue.dateLastSyncAttempt.getTime() > 60 * 1000)) {
         SyncQueue.nbSyncAborted++;
         if (SyncQueue.nbSyncAborted == 2) {
            SyncQueue.showAlert("Attention : l'application ne parvient pas à se connecter. Vos dernières modifications risquent de ne pas être enregistrées. Surveillez l'indicateur de connexion.");
         }
         SyncQueue.status = SyncQueue.statusIdle;
      }
   },

   syncFailed: function(message, retry, failType) {
      if (failType != 3) {
         SyncQueue.nbFailures++;
      }
      SyncQueue.nbFailuresByType[failType]++;
      if (SyncQueue.nbFailures == 2) {
         SyncQueue.showAlert("Attention : l'application ne parvient pas à se connecter. Vos dernières modifications risquent de ne pas être enregistrées. Surveillez l'indicateur de connexion.");
      }
      console.log("Echec " + SyncQueue.nbFailures + " : " + message);
      if (retry) {
         SyncQueue.markStatus(SyncQueue.statusWillSend); // TODO: update
         SyncQueue.setStatus(SyncQueue.statusWillSend);
         var delay = Math.min(SyncQueue.nbFailures, 30);
         delay = Math.max(5, delay);
         setTimeout(SyncQueue.sync, 1000 * delay);
      }
   },

   sync: function(callback) {
      if ((SyncQueue.status == SyncQueue.statusSending) || (SyncQueue.status == SyncQueue.statusSendingWillSend)) {
         SyncQueue.syncCheckActive();
         if (SyncQueue.status != SyncQueue.statusIdle) {
            if (callback != undefined) {
               SyncQueue.laterCallbacks.push(callback);
            }
            return;
         }
      }
      if (callback != undefined) {
         SyncQueue.callbacks.push(callback);
      }
      SyncQueue.numLastAttempt++;
      var numAttempt = SyncQueue.numLastAttempt;
      console.log("sync");// + getFrenchTime());
      SyncQueue.markStatus(SyncQueue.statusSending);
      SyncQueue.setStatus(SyncQueue.statusSending);
      var sentChanges = {};
      for (var modelName in ModelsManager.models) {
         var primaryKey = ModelsManager.getPrimaryKey(modelName);
         var toSync = SyncQueue.objectsToSync[modelName];
         if (!objectHasProperties(toSync)) {
            continue;
         }
         sentChanges[modelName] = {inserted: {}, updated: {}, deleted: {} };
         for (var objectID in toSync) {
            var paramsToSync = toSync[objectID];
            var objectToSync;
            paramsToSync.status = SyncQueue.statusSending;
            if (paramsToSync.action != SyncQueue.actionDelete) {
               var record = ModelsManager.getRecord(modelName, objectID);
               if (record == null) {
                  logError("No record " + objectID + " in " + modelName);
                  continue;
               }
               objectToSync = ModelsManager.copyObject(modelName, record);
               objectToSync[primaryKey] = objectID;
            }
            switch(paramsToSync.action) {
               case SyncQueue.actionInsert:
                  sentChanges[modelName].inserted[objectID] = { data: ModelsManager.convertToSql(modelName, objectToSync) };
                  break;
               case SyncQueue.actionUpdate:
                  sentChanges[modelName].updated[objectID] = { data: ModelsManager.convertToSql(modelName, objectToSync) };
                  break;
               case SyncQueue.actionDelete:
                  sentChanges[modelName].deleted[objectID] = { data: true };
                  break;
            }
         }
      }
      SyncQueue.initRequestsVersions();
      console.log("Changes sent : " + JSON.stringify(sentChanges));
      console.log("requests : " + JSON.stringify(SyncQueue.requests));
      console.log("requestSets : " + JSON.stringify(SyncQueue.requestSets));
      var params = { requests: SyncQueue.requests };
      for (var paramName in SyncQueue.params) {
         params[paramName] = SyncQueue.params[paramName];
      }
      console.log("minServerVersion : " + SyncQueue.serverVersion);
      SyncQueue.dateLastSyncAttempt = ModelsManager.now();
      SyncQueue.lastExecTime = "";
      $.ajax({
         type: "POST",
         url: rootUrl + "sync/syncServer.php",
         data: {
            "minServerVersion": SyncQueue.serverVersion,
            "params": JSON.stringify(params),
            "requestSets": JSON.stringify(SyncQueue.requestSets),
            "changes": JSON.stringify(sentChanges)
         }, // TODO sentChanges may need a lookup (when used in first, prevents the other properties from appearing)
         timeout: 60000,
         success: function(data) {
            try {
               SyncQueue.dateLastSync = ModelsManager.now();
               SyncQueue.nbSyncs++;
               if (SyncQueue.nbFailures == 0) {
                  SyncQueue.nbSyncsWithoutErrors++;
               } else {
                  SyncQueue.nbFailuresTotal += SyncQueue.nbFailures;
               }
               try {
                  data = $.parseJSON(data);
                  console.log(data);
               } catch(exception) {
                  SyncQueue.syncFailed(data, (numAttempt == SyncQueue.numLastAttempt), 1);
                  return;
               }
               for (var listenerName in SyncQueue.futureSyncEndListeners) {
                  SyncQueue.syncEndListeners[listenerName] = SyncQueue.futureSyncEndListeners[listenerName];
                  delete SyncQueue.futureSyncEndListeners[listenerName];
               }
               SyncQueue.callSyncStartListeners(data);
               SyncQueue.lastExecTime = data.execTime;

               ModelsManager.updateDateDiffWithServer(data.serverDateTime);
               SyncQueue.applyChanges(data.changes);
               SyncQueue.updateCounts(data.counts);
               SyncQueue.nbFailures = 0;
               SyncQueue.clearStatus();
               if ((SyncQueue.status === SyncQueue.statusSendingWillSend) || data.continued) {
                  setTimeout(SyncQueue.sync, 1000);
                  SyncQueue.setStatus(SyncQueue.statusWillSend);
                  console.log("back to willSend");
               } else {
                  console.log("back to idle");
                  SyncQueue.setStatus(SyncQueue.statusIdle);
               }
               ModelsManager.sortAllMarked();
               ModelsManager.invokeAllSafeListeners();
               SyncQueue.callSyncEndListeners(data);
               SyncQueue.serverVersion = SyncQueue.resetSync ? 0 : data.serverVersion;
               if (!data.continued) {
                  SyncQueue.hasSyncedFully = true;
               }
               SyncQueue.resetSync = false;
               SyncQueue.updateRequestsVersions();
               var oldCallbacks = SyncQueue.callbacks;
               SyncQueue.callbacks = SyncQueue.laterCallbacks;
               SyncQueue.laterCallbacks = [];
               for (var iCallback = 0; iCallback < oldCallbacks.length; iCallback++) {
                  try {
                     oldCallbacks[iCallback]();
                  } catch(exception) {
                     SyncQueue.nbExceptions++;
                  }
               }
            } catch (exception) {
               console.error("Attention, erreur de synchronisation, vos dernières modifications risquent de ne pas être enregistrées.\n" + exception.message + "\n" + exception.stack);
               SyncQueue.syncFailed("Erreur de synchro", (numAttempt == SyncQueue.numLastAttempt), 2);
            }
         },
         error: function(request, status, err) {
            SyncQueue.syncFailed(status, (numAttempt == SyncQueue.numLastAttempt), 0);
         }
      });
   },
   applyUpdates: function(modelName, rows, requestSetName) {
      var modelObjectsToSync = this.objectsToSync[modelName];
      for (var recordID in rows) {
         // We ignore changes from the server if we have local changes on the same record.
         var objectToSync = modelObjectsToSync[recordID];
         if ((objectToSync == undefined) || (objectToSync.status == this.statusSending)) {
            if (this.modelsManager.oldData[modelName][recordID] != undefined) {
               this.modelsManager.updateFromRowOfStrings(modelName, rows[recordID].data, requestSetName);
            } else {
               this.modelsManager.insertFromRowOfStrings(modelName, rows[recordID].data, requestSetName);
            }
         } else {
            console.log("ignored update from the server");
         }
      }
   },

   applyDeletes: function(modelName, IDs, requestSetName) {
      for (var recordID in IDs) {
         this.modelsManager.deleteRecord(modelName, recordID, requestSetName);
      }
   },

   updateCounts: function(counts) {
      if (Array.isArray(counts)) {
         return;
      }
      this.isApplyingChanges = true;
      for (var requestName in counts) {
         var requestCount = counts[requestName];
         if (this.modelsManager.counts[requestName] == undefined) {
            this.modelsManager.counts[requestName] = 0;
         }
         this.modelsManager.counts[requestName] += requestCount.inserted - requestCount.deleted;
      }
      this.isApplyingChanges = false;
   },

   innerApplyChanges: function(modelName, modelChanges, requestSetName) {
      if (modelChanges != undefined) {
         if (!Array.isArray(modelChanges.inserted)) {
            this.applyUpdates(modelName, modelChanges.inserted, requestSetName);
         }
         if (!Array.isArray(modelChanges.updated)) {
            this.applyUpdates(modelName, modelChanges.updated, requestSetName);
         }
         if (!Array.isArray(modelChanges.deleted)) {
            this.applyDeletes(modelName, modelChanges.deleted, requestSetName);
         }
      }
   },

   applyChanges: function(changes) {
      this.isApplyingChanges = true;
      for (var modelName in this.modelsManager.models) {
         if (modelName == "requestSets") continue;
         this.innerApplyChanges(modelName, changes[modelName.toLowerCase()], 'default');
         for (var requestSetName in changes.requestSets) {
            var requestSet = changes.requestSets[requestSetName];
            if (requestSet[modelName.toLowerCase()] != undefined) {
               this.innerApplyChanges(modelName, requestSet[modelName.toLowerCase()], requestSetName);
            }
         }
      }
      this.isApplyingChanges = false;
   },
};

})();
