<?php

namespace App\Enums;

enum ActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Duplicated = 'duplicated';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Retried = 'retried';
    case Sent = 'sent';
    case Failed = 'failed';
    case TotalsMismatch = 'totals_mismatch';
    case ContactFailed = 'contact_failed';
    case ClientChangeApplied = 'client_change_applied';
    case ClientChangeFailed = 'client_change_failed';
    case ClientChangeRejected = 'client_change_rejected';
    case SmsResent = 'sms_resent';
    case StockAdjustmentAdded = 'stock_adjustment_added';
    case StockAdjustmentDeleted = 'stock_adjustment_deleted';
    case StockAdjustmentRetried = 'stock_adjustment_retried';
    case EvolizStockMismatch = 'evoliz_stock_mismatch';
    case Imported = 'imported';
    case ArticleEdited = 'article_edited';
    case Exported = 'exported';
    case ExportCancelled = 'export_cancelled';
    case SentToEvoliz = 'sent_to_evoliz';
    case SettledStandalone = 'settled_standalone';
    case ExportDownloaded = 'export_downloaded';
}
