(function () {
  function formatDuration(totalSeconds) {
    if (!Number.isFinite(totalSeconds) || totalSeconds < 0) {
      return '';
    }

    var seconds = Math.floor(totalSeconds);
    var hours = Math.floor(seconds / 3600);
    var minutes = Math.floor((seconds % 3600) / 60);
    var remainingSeconds = seconds % 60;

    return String(hours).padStart(2, '0') + ':' +
      String(minutes).padStart(2, '0') + ':' +
      String(remainingSeconds).padStart(2, '0');
  }

  function slugify(value) {
    var normalized = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    return normalized
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'capitulo';
  }

  function formatMegabytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) {
      return '';
    }

    return (bytes / 1048576).toFixed(2);
  }

  function initEditor() {
    var descArea = document.getElementById('content');
    if (!descArea || typeof Jodit === 'undefined') {
      return;
    }

    Jodit.make(descArea, {
      language: 'es',
      toolbar: true,
      buttons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,superscript,subscript,|,copyformat,eraser,clean,|,ul,ol,|,indent,outdent,|,left,center,right,justify,|,link,image,video,table,hr,|,undo,redo,|,find,preview,fullsize,source',
      toolbarAdaptive: false,
      height: 300,
      enter: 'p',
      cleanHTML: { fillEmptyParagraph: false }
    });
  }

  function initAudioHelpers() {
    var audioInput = document.getElementById('audio_file');
    var sizeInput = document.getElementById('audio_size_bytes');
    var sizeMegabytesInput = document.getElementById('audio_size_mb');
    var durationInput = document.getElementById('duration');
    var mimeInput = document.getElementById('audio_mime_type');
    var titleInput = document.getElementById('title');
    var linkInput = document.getElementById('episode_link');
    var generateLinkButton = document.getElementById('generate_link_button');

    if (!audioInput || !sizeInput || !sizeMegabytesInput || !durationInput) {
      return;
    }

    function buildEpisodeLink() {
      if (!titleInput || !linkInput) {
        return;
      }

      var title = (titleInput.value || '').trim();
      if (!title) {
        return;
      }

      var date = new Date();
      var year = String(date.getFullYear());
      var month = String(date.getMonth() + 1).padStart(2, '0');
      linkInput.value = window.location.origin + '/' + year + '/' + month + '/' + slugify(title);
    }

    audioInput.addEventListener('change', function () {
      var file = audioInput.files && audioInput.files[0] ? audioInput.files[0] : null;
      if (!file) {
        return;
      }

      sizeInput.value = String(file.size || '');
      sizeMegabytesInput.value = formatMegabytes(file.size);
      if (mimeInput && file.type) {
        mimeInput.value = file.type;
      }

      var objectUrl = URL.createObjectURL(file);
      var probe = new Audio();
      probe.preload = 'metadata';
      probe.src = objectUrl;

      probe.addEventListener('loadedmetadata', function () {
        durationInput.value = formatDuration(probe.duration);
        URL.revokeObjectURL(objectUrl);
      });

      probe.addEventListener('error', function () {
        URL.revokeObjectURL(objectUrl);
      });
    });

    if (generateLinkButton) {
      generateLinkButton.addEventListener('click', buildEpisodeLink);
    }

    if (titleInput && linkInput) {
      titleInput.addEventListener('input', function () {
        if (linkInput.value.trim() === '') {
          buildEpisodeLink();
        }
      });
    }
  }

  function initPublishDateToggle() {
    var statusSelect = document.getElementById('status_select');
    var pubDateRow = document.getElementById('pub_date_row');
    var contentInput = document.getElementById('content');

    if (!statusSelect || !pubDateRow || !contentInput) {
      return;
    }

    function syncStatusFields() {
      var visible = statusSelect.value === 'published' || statusSelect.value === 'scheduled';
      pubDateRow.classList.toggle('is-hidden', !visible);
      contentInput.required = statusSelect.value !== 'draft';
    }

    statusSelect.addEventListener('change', syncStatusFields);
    syncStatusFields();
  }

  function initRecorder() {
    var details = document.getElementById('recorder-section');
    var btnRecord = document.getElementById('btn-record');
    var btnPause = document.getElementById('btn-pause');
    var btnStop = document.getElementById('btn-stop');
    var btnPreview = document.getElementById('btn-preview-recording');
    var btnUse = document.getElementById('btn-use-recording');
    var recTimer = document.getElementById('rec-timer');
    var recStatus = document.getElementById('rec-status');
    var audioFileInput = document.getElementById('audio_file');

    if (!details || !btnRecord || !btnPause || !btnStop || !btnPreview || !btnUse || !recTimer || !recStatus || !audioFileInput) {
      return;
    }

    var mediaRecorder = null;
    var chunks = [];
    var stream = null;
    var timerInterval = null;
    var startTime = 0;
    var elapsedRecordingTime = 0;
    var mp3Blob = null;
    var audioDuration = 0;
    var decodedAudioBuffer = null;
    var playbackContext = null;
    var playbackSource = null;

    var messages = {
      browserUnsupported: details.dataset.browserUnsupported || '',
      microphoneErrorPrefix: details.dataset.microphoneErrorPrefix || '',
      encoding: details.dataset.encodingMessage || '',
      encodingLong: details.dataset.encodingLongMessage || details.dataset.encodingMessage || '',
      decodeError: details.dataset.decodeErrorMessage || '',
      playbackError: details.dataset.playbackErrorMessage || '',
      uploadErrorPrefix: details.dataset.uploadErrorPrefix || '',
      uploadSaved: details.dataset.uploadSavedMessage || '',
      uploadNetworkError: details.dataset.uploadNetworkErrorMessage || ''
    };

    var useButtonLabels = {
      default: btnUse.dataset.labelDefault || btnUse.textContent,
      uploading: btnUse.dataset.labelUploading || btnUse.textContent,
      uploaded: btnUse.dataset.labelUploaded || btnUse.textContent
    };

    var previewButtonLabels = {
      play: btnPreview.dataset.labelPlay || btnPreview.textContent,
      stop: btnPreview.dataset.labelStop || btnPreview.textContent
    };

    function padTwo(value) {
      return String(value).padStart(2, '0');
    }

    function formatTime(totalSeconds) {
      var hours = Math.floor(totalSeconds / 3600);
      var minutes = Math.floor((totalSeconds % 3600) / 60);
      var seconds = totalSeconds % 60;
      return padTwo(hours) + ':' + padTwo(minutes) + ':' + padTwo(seconds);
    }

    function updateTimer() {
      var elapsed = elapsedRecordingTime;
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        elapsed += Date.now() - startTime;
      }
      recTimer.textContent = formatTime(Math.floor(elapsed / 1000));
    }

    function startTimer(reset) {
      stopTimer();
      if (reset) {
        elapsedRecordingTime = 0;
        recTimer.textContent = formatTime(0);
      }
      startTime = Date.now();
      timerInterval = setInterval(updateTimer, 1000);
    }

    function stopTimer() {
      if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
      }
    }

    function setPauseButtonState(paused) {
      var label = btnPause.querySelector('.rec-pause-label');
      if (label) {
        label.textContent = paused ? btnPause.dataset.labelResume : btnPause.dataset.labelPause;
      }
      btnPause.classList.toggle('is-paused', paused);
      btnPause.setAttribute('aria-pressed', paused ? 'true' : 'false');
    }

    function float32ToInt16(buffer) {
      var output = new Int16Array(buffer.length);

      for (var i = 0; i < buffer.length; i += 1) {
        var sample = Math.max(-1, Math.min(1, buffer[i]));
        output[i] = sample < 0 ? sample * 0x8000 : sample * 0x7FFF;
      }

      return output;
    }

    function stopPreview() {
      if (playbackSource) {
        playbackSource.onended = null;
        try {
          playbackSource.stop();
        } catch (error) {}
        playbackSource.disconnect();
        playbackSource = null;
      }
      btnPreview.textContent = previewButtonLabels.play;
    }

    function encodeToMp3() {
      var chunkMimeType = chunks.length > 0 ? chunks[0].type : '';
      var recordedMimeType = mediaRecorder && mediaRecorder.mimeType
        ? mediaRecorder.mimeType
        : (chunkMimeType || 'audio/webm');
      var blob = new Blob(chunks, { type: recordedMimeType });

      blob.arrayBuffer().then(function (buffer) {
        var AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
        var audioContext = new AudioContextConstructor();

        audioContext.decodeAudioData(buffer, function (audioBuffer) {
          decodedAudioBuffer = audioBuffer;
          audioDuration = audioBuffer.duration;

          var channels = audioBuffer.numberOfChannels;
          var sampleRate = audioBuffer.sampleRate;
          var left = audioBuffer.getChannelData(0);
          var right = channels > 1 ? audioBuffer.getChannelData(1) : left;
          var encoder = new lamejs.Mp3Encoder(channels, sampleRate, 128);
          var mp3Parts = [];
          var blockSize = 1152;

          if (audioDuration > 1800) {
            recStatus.textContent = messages.encodingLong;
          }

          for (var index = 0; index < left.length; index += blockSize) {
            var leftChunk = float32ToInt16(left.subarray(index, index + blockSize));
            var rightChunk = channels > 1 ? float32ToInt16(right.subarray(index, index + blockSize)) : leftChunk;
            var encoded = encoder.encodeBuffer(leftChunk, rightChunk);

            if (encoded.length > 0) {
              mp3Parts.push(encoded);
            }
          }

          var flushed = encoder.flush();
          if (flushed.length > 0) {
            mp3Parts.push(flushed);
          }

          mp3Blob = new Blob(mp3Parts, { type: 'audio/mpeg' });

          var enablePreview = function () {
            // La preescucha usa directamente el AudioBuffer decodificado para
            // evitar los fallos de reproducción de Blob en Firefox.
            btnPreview.hidden = false;
            btnUse.hidden = false;
            recStatus.textContent = '';
            btnRecord.disabled = false;
          };

          audioContext.close().then(enablePreview, enablePreview);
        }, function () {
          recStatus.textContent = messages.decodeError;
          btnRecord.disabled = false;
        });
      });
    }

    btnRecord.addEventListener('click', function () {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
        alert(messages.browserUnsupported);
        return;
      }

      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (nextStream) {
        stream = nextStream;
        chunks = [];
        mp3Blob = null;
        audioDuration = 0;
        decodedAudioBuffer = null;
        stopPreview();
        btnPreview.hidden = true;
        btnUse.hidden = true;
        btnUse.disabled = false;
        btnUse.textContent = useButtonLabels.default;
        recStatus.textContent = '';

        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = function (event) {
          if (event.data && event.data.size > 0) {
            chunks.push(event.data);
          }
        };
        mediaRecorder.onstop = encodeToMp3;
        mediaRecorder.start();

        startTimer(true);
        btnRecord.disabled = true;
        btnRecord.classList.add('is-recording');
        recTimer.classList.add('is-running');
        btnPause.disabled = false;
        setPauseButtonState(false);
        btnStop.disabled = false;
      }).catch(function (error) {
        alert(messages.microphoneErrorPrefix + error.message);
      });
    });

    btnPause.addEventListener('click', function () {
      if (!mediaRecorder) {
        return;
      }

      if (mediaRecorder.state === 'recording') {
        elapsedRecordingTime += Date.now() - startTime;
        mediaRecorder.pause();
        stopTimer();
        updateTimer();
        setPauseButtonState(true);
        btnRecord.classList.remove('is-recording');
        recTimer.classList.remove('is-running');
        return;
      }

      if (mediaRecorder.state === 'paused') {
        mediaRecorder.resume();
        startTimer(false);
        setPauseButtonState(false);
        btnRecord.classList.add('is-recording');
        recTimer.classList.add('is-running');
      }
    });

    btnStop.addEventListener('click', function () {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        elapsedRecordingTime += Date.now() - startTime;
      }
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
      }

      if (stream) {
        stream.getTracks().forEach(function (track) {
          track.stop();
        });
        stream = null;
      }

      stopTimer();
      updateTimer();
      btnRecord.classList.remove('is-recording');
      recTimer.classList.remove('is-running');
      recStatus.textContent = messages.encoding;
      btnPause.disabled = true;
      setPauseButtonState(false);
      btnStop.disabled = true;
    });

    btnPreview.addEventListener('click', function () {
      if (!decodedAudioBuffer) {
        return;
      }

      if (playbackSource) {
        stopPreview();
        return;
      }

      var AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
      if (!playbackContext || playbackContext.state === 'closed') {
        playbackContext = new AudioContextConstructor();
      }

      playbackContext.resume().then(function () {
        var source = playbackContext.createBufferSource();
        source.buffer = decodedAudioBuffer;
        source.connect(playbackContext.destination);
        source.onended = function () {
          if (playbackSource === source) {
            playbackSource = null;
            btnPreview.textContent = previewButtonLabels.play;
          }
        };
        playbackSource = source;
        btnPreview.textContent = previewButtonLabels.stop;
        recStatus.textContent = '';
        source.start();
      }).catch(function () {
        stopPreview();
        recStatus.textContent = messages.playbackError;
      });
    });

    btnUse.addEventListener('click', function () {
      if (!mp3Blob) {
        return;
      }

      var csrfInput = document.querySelector('input[name="csrf_token"]');
      if (!csrfInput) {
        return;
      }

      var now = new Date();
      var fileName = 'grabacion-' + now.getFullYear() + '-' +
        padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate()) + '.mp3';

      btnUse.disabled = true;
      btnUse.textContent = useButtonLabels.uploading;
      recStatus.textContent = '';

      var formData = new FormData();
      formData.append('csrf_token', csrfInput.value);
      formData.append('audio_file', mp3Blob, fileName);

      fetch('upload_audio_ajax.php', { method: 'POST', body: formData })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data.error) {
            recStatus.textContent = messages.uploadErrorPrefix + data.error;
            btnUse.disabled = false;
            btnUse.textContent = useButtonLabels.default;
            return;
          }

          var audioUrlInput = document.querySelector('input[name="audio_url"]');
          var mimeInput = document.getElementById('audio_mime_type');
          var sizeInput = document.getElementById('audio_size_bytes');
          var sizeMegabytesInput = document.getElementById('audio_size_mb');
          var durationInput = document.getElementById('duration');

          if (audioUrlInput) {
            audioUrlInput.value = data.url;
          }
          if (mimeInput) {
            mimeInput.value = data.mime || 'audio/mpeg';
          }
          if (sizeInput) {
            sizeInput.value = String(data.size || '');
          }
          if (sizeMegabytesInput) {
            sizeMegabytesInput.value = formatMegabytes(Number(data.size));
          }
          if (durationInput && audioDuration > 0) {
            durationInput.value = formatDuration(audioDuration);
          }

          btnUse.textContent = useButtonLabels.uploaded;
          recStatus.textContent = messages.uploadSaved;
          details.removeAttribute('open');
        })
        .catch(function () {
          recStatus.textContent = messages.uploadNetworkError;
          btnUse.disabled = false;
          btnUse.textContent = useButtonLabels.default;
        });
    });
  }

  function initAddEpisode() {
    initEditor();
    initAudioHelpers();
    initPublishDateToggle();
    initRecorder();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAddEpisode);
  } else {
    initAddEpisode();
  }
}());
