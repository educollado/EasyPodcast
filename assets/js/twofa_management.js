(function () {
  function initTwofaQr() {
    var container = document.getElementById('qr-container');
    if (!container || typeof QRCode === 'undefined') {
      return;
    }

    var qrUri = container.dataset.qrUri || '';
    if (qrUri === '') {
      return;
    }

    new QRCode(container, {
      text: qrUri,
      width: 200,
      height: 200,
      colorDark: '#000',
      colorLight: '#fff',
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTwofaQr);
  } else {
    initTwofaQr();
  }
}());
