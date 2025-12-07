@extends('viewmanager::layouts.app')

@section('page-title', 'Downloader')

@section('content')
<div class="row mb-2 g-4">
  <div class="col-12 col-sm-6 col-xl-4 col-xxl-3">
    <div class="card">
      <div class="card-body p-3 d-flex align-items-center">
        <div class="bg-primary text-white p-3 me-3">
          <svg class="icon icon-xl">
            <use xlink:href="{{ asset('vendors/@coreui/icons/svg/free.svg#cil-cloud-download') }}"></use>
          </svg>
        </div>
        <div>
          <div class="fs-6 fw-semibold text-primary">{{ $userStats["total_downloads"] }}</div>
          <div class="text-body-secondary text-uppercase fw-semibold small">Downloads</div>
        </div>
      </div>
    </div>
  </div>
  <!-- /.col-->
  <div class="col-12 col-sm-6 col-xl-4 col-xxl-3">
    <div class="card">
      <div class="card-body p-3 d-flex align-items-center">
        <div class="bg-info text-white p-3 me-3">
          <svg class="icon icon-xl">
            <use xlink:href="{{ asset('vendors/@coreui/icons/svg/free.svg#cil-check') }}"></use>
          </svg>
        </div>
        <div>
          <div class="fs-6 fw-semibold text-info">{{ $userStats["completed_downloads"] }}</div>
          <div class="text-body-secondary text-uppercase fw-semibold small">Completed</div>
        </div>
      </div>
    </div>
  </div>
  <!-- /.col-->
  <div class="col-12 col-sm-6 col-xl-4 col-xxl-3">
    <div class="card">
      <div class="card-body p-3 d-flex align-items-center">
        <div class="bg-warning text-white p-3 me-3">
          <svg class="icon icon-xl">
            <use xlink:href="{{ asset('vendors/@coreui/icons/svg/free.svg#cil-sync') }}"></use>
          </svg>
        </div>
        <div>
          <div class="fs-6 fw-semibold text-warning">{{ $userStats["active_downloads"] }}</div>
          <div class="text-body-secondary text-uppercase fw-semibold small">Active</div>
        </div>
      </div>
    </div>
  </div>
  <!-- /.col-->
  <div class="col-12 col-sm-6 col-xl-4 col-xxl-3">
    <div class="card">
      <div class="card-body p-3 d-flex align-items-center">
        <div class="bg-danger text-white p-3 me-3">
          <svg class="icon icon-xl">
            <use xlink:href="{{ asset('vendors/@coreui/icons/svg/free.svg#cil-storage') }}"></use>
          </svg>
        </div>
        <div>
          <div class="fs-6 fw-semibold text-danger">{{ $userStats["total_download_size"] }}</div>
          <div class="text-body-secondary text-uppercase fw-semibold small">Total Size</div>
        </div>
      </div>
    </div>
  </div>
  <!-- /.col-->
</div>
<div class="card">
  <div class="card-header"><strong>Downloader</strong></div>
  <div class="card-body">
    <div class="row mb-2">
      <div class="col">
        <div class="form-floating mb-3">
          <input type="text" name="url" id="form-url-input" class="form-control" placeholder="https://www.example.com/downloads" required>
          <label for="form-url-input">Masukkan URL</label>
        </div>
        <div class="mt-4 pt-2 border-top border-primary">
          <button type="button" id="check-button" class="btn btn-success">
            <svg class="icon me-2">
              <use xlink:href="{{ asset('vendors/@coreui/icons/svg/free.svg#cil-paper-plane') }}"></use>
            </svg>
            Check
          </button>
        </div>
      </div>
    </div>
    <div class="row mt-2">
      <div class="col">
        <div class="table-responsive">
          <table class="table table-bordered table-hover">
            <thead>
              <th>Filename</th>
              <th>Progress</th>
            </thead>
            <tbody id="tbody-download"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="previewDownload" data-coreui-backdrop="static" data-coreui-keyboard="false" tabindex="-1" aria-labelledby="previewDownloadLabel" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewDownloadLabel">Preview</h5>
        <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="modal-body">
        <div class="container-fluid">
          <p class="fw-wight-bold">Memproses...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
  let prevModal;
  
  function download(url){
    if(!url) return;
    
    window.location = 
    '{{env("APP_URL") }}/api/v1/downloaders/download?url='+ url;
  }
  
  window.addEventListener("DOMContentLoaded", function() {
    const urlInput = document.getElementById('form-url-input');
    const checkButton = document.getElementById('check-button');
    
    checkButton.addEventListener('click', function() {
      if(!urlInput.value) {
        urlInput.classList.add('is-invalid');
        return;
      }
        
      new coreui.Modal('#previewDownload').show();
    });
    
    prevModal = document.getElementById('previewDownload');
    const modalBody = document.getElementById('modal-body');
    
    prevModal.addEventListener('shown.coreui.modal', function() {
      modalBody.innerHTML = '<p class="fw-wight-bold">Processing...</p>';
      
      fetch('{{ env("APP_URL") }}/api/v1/downloaders/preview?url='+ urlInput.value).then(res => res.json()).then(data => {
        let contentModal = '';
        if(!data.success) {
          contentModal = "Gagal mendapatkan informasi file";
        } else {
          contentModal = `<div class="row">
            <div class="col-md-4">
              <strong>Filename</strong>
            </div>
            <div class="col-md-4 ms-auto">
              <span class="text-muted">${data.data.filename}</span>
            </div>
          </div>
          <div class="row">
            <div class="col-md-4">
              <strong>Size</strong>
            </div>
            <div class="col-md-4 ms-auto">
              <span class="text-muted">${data.data.file_size}</span>
            </div>
          </div>
          <div class="row">
            <div class="col">
              <div class="row my-4 pb-2 border-bottom border-primary">
                <strong>Metadata</strong>
              </div>
              <div class="row">
                <div class="col-md-4">
                  <strong>URL</strong>
                </div>
                <div class="col-md-4 ms-auto">
                  <span class="text-muted">${data.data.url_analysis?.url}</span>
                </div>
              </div>
              <div class="row">
                <div class="col-md-4">
                  <strong>Type</strong>
                </div>
                <div class="col-md-4 ms-auto">
                  <span class="text-muted">${data.data.url_analysis?.type_label}</span>
                </div>
              </div>
              <div class="row mt-2">
                <div class="col-md-4">
                  <strong>Downloadable</strong>
                </div>
                <div class="col-md-4 ms-auto">
                  <span class="text-${data.data.is_downloadable ? 'success' : 'danger'}">${data.data.is_downloadable ? 'Yes' : 'No'}</span>
                </div>
              </div>
            </div>
          </div>`;
          
          const downloadButton = `<button type="button" class="btn btn-primary" onclick="download('${data.data.url_analysis.url}')">Download</button>`;
          
          document.querySelector('.modal-footer').insertAdjacentHTML('beforeend', downloadButton);
        }
        
        modalBody.innerHTML = contentModal;
      });
    });
  });
</script>
@endsection