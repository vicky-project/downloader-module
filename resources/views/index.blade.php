@extends('viewmanager::layouts.app')

@section('page-title', 'Downloader')

@section('content')
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
              <th scope="col">Filename</th>
              <th scope="col">Progress</th>
              <th scope="col">Status</th>
            </thead>
            <tbody id="tbody-download">
            </tbody>
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
      <form method="POST" action="{{ route('downloader.download') }}">
        @csrf
        <input type="hidden" name="url" value="" id="download-url">
      <div class="modal-body" id="modal-body">
        <div class="container-fluid">
          <p class="fw-wight-bold">Memproses...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal">Cancel</button>
      </div>
      </form>
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
  
  function initDownloadStream(jobId){
    const eventSource = new EventSource('{{ env("APP_URL") }}/api/v1/downloaders/stream/' + jobId);
    
    eventSource.onmessage = function(event) {
      const data = JSON.parse(event.data);
      
      updateProgressDownload(jobId, data);
    }
    
    eventSource.onerror = function() {
      console.log("Stream for job id: ${jobId} closed");
      eventSource.close();
    }
  }
  
  function updateProgressDownload(jobId, data) {
    const progressBar = document.getElementById('job-progress-'+ jobId);
    const jobPercentage = document.getElementById('job-progress-percentage-' + jobId);
    const jobProgressValue = document.getElementById('job-progress-value-' + jobId);
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
              <span class="text-muted">${data.data.formatted_size}</span>
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
                  <span class="text-muted">${data.data.url}</span>
                </div>
              </div>
              <div class="row">
                <div class="col-md-4">
                  <strong>Type</strong>
                </div>
                <div class="col-md-4 ms-auto">
                  <span class="text-muted">${data.data.mime_type}</span>
                </div>
              </div>
            </div>
          </div>`;
          
          document.getElementById('download-url').value = urlInput.value;
          
          const downloadButton = `<button type="submit" class="btn btn-primary" id="btn-submit">Download</button>`;
          
          const submitButton = document.getElementById('btn-submit'));
          if(submitButton) submitButton.remove();
          
          document.querySelector('.modal-footer').insertAdjacentHTML('beforeend', downloadButton);
        }
        
        modalBody.innerHTML = contentModal;
      });
    });
  });
</script>
@endsection