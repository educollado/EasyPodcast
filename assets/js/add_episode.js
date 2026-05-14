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
    var durationInput = document.getElementById('duration');
    var mimeInput = document.getElementById('audio_mime_type');
    var titleInput = document.getElementById('title');
    var linkInput = document.getElementById('episode_link');
    var generateLinkButton = document.getElementById('generate_link_button');

    if (!audioInput || !sizeInput || !durationInput) {
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
    var btnStop = document.getElementById('btn-stop');
    var btnUse = document.getElementById('btn-use-recording');
    var recTimer = document.getElementById('rec-timer');
    var recStatus = document.getElementById('rec-status');
    var recPreview = document.getElementById('rec-preview');
    var audioFileInput = document.getElementById('audio_file');

    if (!details || !btnRecord || !btnStop || !btnUse || !recTimer || !recStatus || !recPreview || !audioFileInput) {
      return;
    }

    var mediaRecorder = null;
    var chunks = [];
    var stream = null;
    var timerInterval = null;
    var startTime = 0;
    var mp3Blob = null;
    var audioDuration = 0;

    var messages = {
      browserUnsupported: details.dataset.browserUnsupported || '',
      microphoneErrorPrefix: details.dataset.microphoneErrorPrefix || '',
      encoding: details.dataset.encodingMessage || '',
      encodingLong: details.dataset.encodingLongMessage || details.dataset.encodingMessage || '',
      decodeError: details.dataset.decodeErrorMessage || '',
      uploadErrorPrefix: details.dataset.uploadErrorPrefix || '',
      uploadSaved: details.dataset.uploadSavedMessage || '',
      uploadNetworkError: details.dataset.uploadNetworkErrorMessage || ''
    };

    var useButtonLabels = {
      default: btnUse.dataset.labelDefault || btnUse.textContent,
      uploading: btnUse.dataset.labelUploading || btnUse.textContent,
      uploaded: btnUse.dataset.labelUploaded || btnUse.textContent
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

    function startTimer() {
      startTime = Date.now();
      timerInterval = setInterval(function () {
        var elapsed = Math.floor((Date.now() - startTime) / 1000);
        recTimer.textContent = formatTime(elapsed);
      }, 1000);
    }

    function stopTimer() {
      if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
      }
    }

    function float32ToInt16(buffer) {
      var output = new Int16Array(buffer.length);

      for (var i = 0; i < buffer.length; i += 1) {
        var sample = Math.max(-1, Math.min(1, buffer[i]));
        output[i] = sample < 0 ? sample * 0x8000 : sample * 0x7FFF;
      }

      return output;
    }

    function encodeToMp3() {
      var blob = new Blob(chunks, { type: 'audio/webm' });

      blob.arrayBuffer().then(function (buffer) {
        var AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
        var audioContext = new AudioContextConstructor();

        audioContext.decodeAudioData(buffer, function (audioBuffer) {
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

          recPreview.src = URL.createObjectURL(mp3Blob);
          recPreview.hidden = false;
          btnUse.hidden = false;
          recStatus.textContent = '';
          btnRecord.disabled = false;

          audioContext.close().catch(function () {});
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
        recPreview.hidden = true;
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

        startTimer();
        btnRecord.disabled = true;
        btnRecord.classList.add('is-recording');
        recTimer.classList.add('is-running');
        btnStop.disabled = false;
      }).catch(function (error) {
        alert(messages.microphoneErrorPrefix + error.message);
      });
    });

    btnStop.addEventListener('click', function () {
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
      btnRecord.classList.remove('is-recording');
      recTimer.classList.remove('is-running');
      recStatus.textContent = messages.encoding;
      btnStop.disabled = true;
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
