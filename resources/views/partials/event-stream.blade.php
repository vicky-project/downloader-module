<script>
class DownloadEventStream {
  constructor() {
    this.eventSource = null;
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 5;
    this.reconnectDelay = 3000;
    this.activeStreams = new Map();
  }

  /**
   * Connect to main EventStream for all downloads
   */
  connect() {
    if (this.eventSource) {
      this.disconnect();
    }
    
    const eventStreamUrl = "{{ route('api.downloader.stream') }}";
    
    this.eventSource = new EventSource(eventStreamUrl);
    
    this.eventSource.onopen = (event) => {
      console.log('EventStream connected');
      this.reconnectAttempts = 0;
    };
    
    this.eventSource.addEventListener('connected', (event) => {
      const data = JSON.parse(event.data);
      console.log('EventStream connected:', data);
    });
    
    this.eventSource.addEventListener('progress', (event) => {
      const data = JSON.parse(event.data);
      this.handleProgressUpdate(data);
    });
    
    this.eventSource.addEventListener('completed', (event) => {
      const data = JSON.parse(event.data);
      this.handleDownloadCompleted(data);
    });
    
    this.eventSource.addEventListener('failed', (event) => {
      const data = JSON.parse(event.data);
      this.handleDownloadFailed(data);
    });
    
    this.eventSource.addEventListener('keep-alive', (event) => {
      // Keep-alive received, connection is healthy
      console.log('EventStream keep-alive');
    });
    
    this.eventSource.onerror = (event) => {
      console.error('EventStream error:', event);
      this.handleDisconnection();
    };
  }
  
  /**
   * Handle progress update from EventStream
   */
  handleProgressUpdate(data) {
    const { job_id, progress, filename, status, file_size, speed, eta } = data;
    
    // Update UI elements
    this.updateProgressBar(job_id, progress);
    this.updateFilename(job_id, filename);
    this.updateFileSize(job_id, file_size);
    this.updateSpeed(job_id, speed);
    this.updateETA(job_id, eta);
    this.updateStatus(job_id, status);
    
    // Update global stats
    this.updateGlobalStats();
  }
  
  /**
   * Handle download completion
   */
  handleDownloadCompleted(data) {
    const { job_id, filename, download_url } = data;
    
    // Update UI to show completion
    this.markAsCompleted(job_id, filename);
    
    // Show download button
    this.showDownloadButton(job_id, download_url);
    
    // Remove from active streams if single stream
    if (this.activeStreams.has(job_id)) {
      this.activeStreams.get(job_id).close();
      this.activeStreams.delete(job_id);
    }
    
    // Update global stats
    this.updateGlobalStats();
    
    // Show notification
    this.showNotification('Download Completed', `${filename} has been downloaded successfully.`, 'success');
  }
  
  /**
   * Handle download failure
   */
  handleDownloadFailed(data) {
    const { job_id, filename, error_message } = data;
    
    // Update UI to show failure
    this.markAsFailed(job_id, filename, error_message);
    
    // Remove from active streams if single stream
    if (this.activeStreams.has(job_id)) {
      this.activeStreams.get(job_id).close();
      this.activeStreams.delete(job_id);
    }
    
    // Update global stats
    this.updateGlobalStats();
    
    // Show error notification
    this.showNotification('Download Failed', `${filename}: ${error_message}`, 'error');
  }
  
  /**
   * Handle disconnection and attempt reconnect
  */
  handleDisconnection() {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
    
    if (this.reconnectAttempts < this.maxReconnectAttempts) {
      this.reconnectAttempts++;
      console.log(`Attempting to reconnect... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
      
      setTimeout(() => {
        this.connect();
      }, this.reconnectDelay * this.reconnectAttempts);
    } else {
      console.error('Max reconnection attempts reached');
      this.showNotification('Connection Lost', 'Unable to connect to download progress stream.', 'warning');
    }
  }
  
  /**
   * Disconnect all EventStreams
   */
  disconnect() {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
    
    // Close all single streams
    this.activeStreams.forEach((stream, jobId) => {
      stream.close();
    });
    this.activeStreams.clear();
  }
  
  /**
   * UI Helper Methods
   */
  updateProgressBar(jobId, progress) {
    const progressBar = document.querySelector(`[data-job-id="${jobId}"] .progress-bar`);
    if (progressBar) {
      progressBar.style.width = `${progress}%`;
      progressBar.textContent = `${Math.round(progress)}%`;
    }
  }
  
  updateFilename(jobId, filename) {
    const filenameElement = document.querySelector(`[data-job-id="${jobId}"] .filename`);
    if (filenameElement) {
      filenameElement.textContent = filename;
    }
  }
  
  updateFileSize(jobId, fileSize) {
    const sizeElement = document.querySelector(`[data-job-id="${jobId}"] .file-size`);
    if (sizeElement && fileSize) {
      sizeElement.textContent = this.formatFileSize(fileSize);
    }
  }
  
  updateSpeed(jobId, speed) {
    const speedElement = document.querySelector(`[data-job-id="${jobId}"] .download-speed`);
    if (speedElement && speed) {
      speedElement.textContent = `${speed} KB/s`;
    }
  }
  
  updateETA(jobId, eta) {
    const etaElement = document.querySelector(`[data-job-id="${jobId}"] .eta`);
    if (etaElement && eta) {
      etaElement.textContent = this.formatTime(eta);
    }
  }
  
  updateStatus(jobId, status) {
    const statusElement = document.querySelector(`[data-job-id="${jobId}"] .status`);
    if (statusElement) {
      statusElement.textContent = status;
      statusElement.className = `status status-${status.toLowerCase()}`;
    }
  }
  
  markAsCompleted(jobId, filename) {
    const item = document.querySelector(`[data-job-id="${jobId}"]`);
    if (item) {
      item.classList.add('completed');
      item.classList.remove('downloading');
    }
  }
  
  markAsFailed(jobId, filename, errorMessage) {
    const item = document.querySelector(`[data-job-id="${jobId}"]`);
    if (item) {
      item.classList.add('failed');
      item.classList.remove('downloading');
      
      const errorElement = item.querySelector('.error-message');
      if (errorElement) {
        errorElement.textContent = errorMessage;
        errorElement.style.display = 'block';
      }
    }
  }
  
  showDownloadButton(jobId, downloadUrl) {
    const item = document.querySelector(`[data-job-id="${jobId}"]`);
    if (item) {
      let downloadBtn = item.querySelector('.download-btn');
      if (!downloadBtn) {
        downloadBtn = document.createElement('a');
        downloadBtn.className = 'btn btn-success btn-sm download-btn';
        downloadBtn.innerHTML = '<i class="fas fa-download"></i> Download';
        item.querySelector('.actions').appendChild(downloadBtn);
      }
      downloadBtn.href = downloadUrl;
      downloadBtn.style.display = 'inline-block';
    }
  }
  
  updateGlobalStats() {
    // Update active downloads count
    const activeItems = document.querySelectorAll('.download-item.downloading');
    document.getElementById('activeDownloadsCount').textContent = activeItems.length;
    
    // Update overall progress if needed
    this.updateOverallProgress();
  }
  
  updateOverallProgress() {
    const progressBars = document.querySelectorAll('.download-item .progress-bar');
    if (progressBars.length === 0) return;
    
    let totalProgress = 0;
    progressBars.forEach(bar => {
      const progress = parseInt(bar.style.width) || 0;
      totalProgress += progress;
    });
    
    const avgProgress = Math.round(totalProgress / progressBars.length);
    const overallBar = document.getElementById('overallProgressBar');
    if (overallBar) {
      overallBar.style.width = `${avgProgress}%`;
      overallBar.textContent = `${avgProgress}%`;
    }
  }
  
  /**
   * Utility Methods
   */
  formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
  
  formatTime(seconds) {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${hours}h ${minutes}m`;
  }
  
  showNotification(title, message, type = 'info') {
    // Implement your notification system here
    // Could use Toastr, SweetAlert, or custom notification
    console.log(`${type.toUpperCase()}: ${title} - ${message}`);
    
    // Example with Toastr (if available)
    if (typeof toastr !== 'undefined') {
      toastr[type](message, title);
    }
  }
}

// Initialize EventStream when page loads
document.addEventListener('DOMContentLoaded', function() {
    window.downloadEventStream = new DownloadEventStream();
    
    // Connect to main EventStream
    window.downloadEventStream.connect();
    
    // Load initial active downloads
    // fetchActiveDownloads();
    
    // Reconnect when page becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && !window.downloadEventStream.eventSource) {
            window.downloadEventStream.connect();
        }
    });
});

/**
 * Fetch initial active downloads
 */
async function fetchActiveDownloads() {
    try {
        const response = await fetch("{{ route('download.active') }}");
        const result = await response.json();
        
        if (result.success) {
            result.data.forEach(download => {
                // Create UI elements for each active download
                createDownloadItem(download);
                
                // Connect to individual stream for better updates
                if (download.status === 'downloading' || download.status === 'pending') {
                    window.downloadEventStream.connectSingle(download.job_id);
                }
            });
        }
    } catch (error) {
        console.error('Failed to fetch active downloads:', error);
    }
}

/**
 * Create download item in UI
 */
function createDownloadItem(download) {
    const container = document.getElementById('activeDownloadsList');
    if (!container) return;
    
    const template = `
        <div class="download-item" data-job-id="${download.job_id}">
            <div class="download-info">
                <div class="filename">${download.filename}</div>
                <div class="file-size">${window.downloadEventStream.formatFileSize(download.file_size || 0)}</div>
                <div class="status status-${download.status}">${download.status}</div>
            </div>
            <div class="progress">
                <div class="progress-bar" style="width: ${download.progress}%">
                    ${Math.round(download.progress)}%
                </div>
            </div>
            <div class="download-details">
                <div class="download-speed">-- KB/s</div>
                <div class="eta">--</div>
            </div>
            <div class="actions">
                <button class="btn btn-danger btn-sm cancel-btn" onclick="cancelDownload('${download.job_id}')">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', template);
}

/**
 * Cancel a download
 */
async function cancelDownload(jobId) {
    if (!confirm('Are you sure you want to cancel this download?')) {
        return;
    }
    
    try {
        const response = await fetch(`/download/cancel/${jobId}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Remove from UI
            const item = document.querySelector(`[data-job-id="${jobId}"]`);
            if (item) {
                item.remove();
            }
            
            // Update stats
            window.downloadEventStream.updateGlobalStats();
            
            // Show notification
            window.downloadEventStream.showNotification('Download Cancelled', 'Download has been cancelled successfully.', 'info');
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        console.error('Failed to cancel download:', error);
        window.downloadEventStream.showNotification('Error', 'Failed to cancel download: ' + error.message, 'error');
    }
}
</script>

<style>
.download-item {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}

.download-item.downloading {
    border-left: 4px solid #007bff;
}

.download-item.completed {
    border-left: 4px solid #28a745;
    opacity: 0.8;
}

.download-item.failed {
    border-left: 4px solid #dc3545;
}

.download-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.filename {
    font-weight: bold;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-size {
    color: #666;
    margin: 0 10px;
}

.status {
    font-weight: bold;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.status-downloading {
    background: #e3f2fd;
    color: #007bff;
}

.status-completed {
    background: #e8f5e9;
    color: #28a745;
}

.status-failed {
    background: #ffebee;
    color: #dc3545;
}

.progress {
    height: 10px;
    background: #eee;
    border-radius: 5px;
    overflow: hidden;
    margin: 10px 0;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #00bfff);
    transition: width 0.5s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 10px;
    font-weight: bold;
}

.download-details {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #666;
    margin-bottom: 10px;
}

.actions {
    display: flex;
    gap: 10px;
}

.error-message {
    color: #dc3545;
    font-size: 12px;
    margin-top: 5px;
    display: none;
}
</style>