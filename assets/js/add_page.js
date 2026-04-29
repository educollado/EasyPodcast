(function () {
  function slugify(value) {
    return value
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || '';
  }

  function initEditor() {
    var contentArea = document.getElementById('page_content');
    if (!contentArea || typeof Jodit === 'undefined') {
      return;
    }

    Jodit.make(contentArea, {
      language: 'es',
      toolbar: true,
      buttons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,superscript,subscript,|,copyformat,eraser,clean,|,ul,ol,|,indent,outdent,|,left,center,right,justify,|,link,image,video,table,hr,|,undo,redo,|,find,preview,fullsize,source',
      toolbarAdaptive: false,
      height: 300,
      enter: 'p',
      cleanHTML: { fillEmptyParagraph: false }
    });
  }

  function initSlugAutofill() {
    var titleInput = document.getElementById('page_title');
    var slugInput = document.getElementById('page_slug');
    var slugDirty = slugInput && slugInput.value !== '';

    if (!titleInput || !slugInput) {
      return;
    }

    slugInput.addEventListener('input', function () {
      slugDirty = true;
    });

    titleInput.addEventListener('input', function () {
      if (!slugDirty) {
        slugInput.value = slugify(titleInput.value);
      }
    });
  }

  function initAddPage() {
    initEditor();
    initSlugAutofill();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAddPage);
  } else {
    initAddPage();
  }
}());
