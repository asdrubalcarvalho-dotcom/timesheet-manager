import React, { useState } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogContentText,
  DialogActions,
  Button,
  TextField
} from '@mui/material';

interface InputDialogProps {
  open: boolean;
  title: string;
  message: string;
  label?: string;
  required?: boolean;
  multiline?: boolean;
  rows?: number;
  confirmText?: string;
  cancelText?: string;
  onConfirm: (value: string) => void;
  onCancel: () => void;
}

const InputDialog: React.FC<InputDialogProps> = ({
  open,
  title,
  message,
  label = 'Input',
  required = true,
  multiline = false,
  rows = 1,
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  onConfirm,
  onCancel,
}) => {
  const [value, setValue] = useState('');

  const handleConfirm = () => {
    if (required && !value.trim()) {
      return;
    }
    onConfirm(value);
    setValue('');
  };

  const handleCancel = () => {
    setValue('');
    onCancel();
  };

  return (
    <Dialog
      open={open}
      onClose={handleCancel}
      maxWidth="sm"
      fullWidth
    >
      <DialogTitle>{title}</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 2 }}>
          {message}
        </DialogContentText>
        <TextField
          autoFocus
          margin="dense"
          label={label}
          fullWidth
          multiline={multiline}
          rows={multiline ? rows : undefined}
          value={value}
          onChange={(e) => setValue(e.target.value)}
          required={required}
          onKeyPress={(e) => {
            if (e.key === 'Enter' && !multiline) {
              handleConfirm();
            }
          }}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={handleCancel} color="inherit">
          {cancelText}
        </Button>
        <Button 
          onClick={handleConfirm} 
          variant="contained" 
          disabled={required && !value.trim()}
        >
          {confirmText}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default InputDialog;
