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
    <div class="row mb-2">
      <div class="col"></div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="previewDownload" data-coreui-backdrop="static" data-coreui-keyboard="false" tabindex="-1" aria-labelledby="previewDownloadLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewDownloadLabel">Modal title</h5>
        <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        ...
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary">Download</button>
      </div>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
  window.addEventListener("DOMContentLoaded", function() {
    
    const checkButton = document.getElementById('check-button');
    checkButton.addEventListener('click', function() {
        const urlInput = document.getElementById('form-url-input');
      
        if(!urlInput.value) {
          urlInput.classList.add('is-invalid');
          return;
        }
        
        new coreui.Modal('#previewDownload').show();
    });
    
    const prevModal = document.getElementById('previewDownload');
    
    prevModal.addEventListener('shown.coreui.modal', function() {
      
      fetch('{{ env("APP_URL") }}/api/v1/downloaders/preview?url='+ urlInput.value).then(res => res.json()).then(data => console.log(data));
    });
  });
</script>
@endsection